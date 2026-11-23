<?php

namespace Tests\Feature;

use App\Console\Commands\ReplayMetaFixtures;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Jobs\ProcessMetaWebhook;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase3MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Legacy agency parsing is off by default for Smash; these fixtures cover it explicitly.
        config(['leads.agency_parsing' => true, 'leads.agency_numbers' => ['919900000001'], 'leads.dedupe_days' => 7]);
    }

    private function fixture(string $name): array
    {
        return ReplayMetaFixtures::load(base_path("tests/fixtures/meta/{$name}.json"));
    }

    private function process(string $name): void
    {
        app(MetaWebhookProcessor::class)->process($this->fixture($name));
    }

    public function test_verification_handshake(): void
    {
        config(['leads.meta.verify_token' => 'secret-token']);

        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=secret-token&hub.challenge=12345')
            ->assertOk()->assertSeeText('12345');
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();

        config(['leads.meta.verify_token' => null]);
        $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
    }

    public function test_post_verifies_signature_and_queues_without_csrf(): void
    {
        Queue::fake();
        config(['leads.meta.app_secret' => 'app-secret']);
        $body = json_encode($this->fixture('whatsapp_text'));
        $sign = fn ($b) => ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $b, 'app-secret'), 'Content-Type' => 'application/json'];

        $this->call('POST', '/webhooks/meta', [], [], [], $this->transformHeadersToServerVars(['X-Hub-Signature-256' => 'sha256=bad', 'Content-Type' => 'application/json']), $body)
            ->assertForbidden();
        Queue::assertNothingPushed();

        $this->call('POST', '/webhooks/meta', [], [], [], $this->transformHeadersToServerVars($sign($body)), $body)
            ->assertOk()->assertSeeText('EVENT_RECEIVED');
        Queue::assertPushed(ProcessMetaWebhook::class, fn ($job) => $job->payload['object'] === 'whatsapp_business_account');
        $this->assertSame(0, Lead::count()); // processed by the worker, not in the request
    }

    public function test_missing_app_secret_is_allowed_in_mock_but_rejected_in_live(): void
    {
        Queue::fake();
        config(['leads.meta.app_secret' => null]);

        $this->postJson('/webhooks/meta', $this->fixture('whatsapp_text'))->assertOk();

        config(['leads.mode' => 'live']);
        $this->postJson('/webhooks/meta', $this->fixture('whatsapp_text'))->assertForbidden();
    }

    public function test_agency_numbers_are_plain_customers_when_agency_parsing_is_off(): void
    {
        config(['leads.agency_parsing' => false]);
        $this->artisan('meta:replay', ['fixtures' => ['whatsapp_agency_text']])->assertSuccessful();

        $this->assertTrue(Lead::where('source', LeadSource::WhatsappAgency)->doesntExist());
        $this->assertSame(1, Lead::where('source', LeadSource::WhatsappCustomer)->count());
    }

    public function test_replay_command_processes_every_fixture_and_is_idempotent(): void
    {
        $this->artisan('meta:replay')->assertSuccessful();

        $fb = Lead::where('source', LeadSource::FbLeadAd)->sole();
        $this->assertSame(['Neha Kulkarni', '+919812345601', 'neha.k@example.com', 'Mumbai', 'Party Enquiry Form', 'Birthday Parties – Dec', 'Carousel – Party Packages'],
            [$fb->name, $fb->phone, $fb->email, $fb->city, $fb->form_name, $fb->campaign_name, $fb->ad_name]);
        $this->assertSame('900000000000001', $fb->external_id);

        $ig = Lead::where('source', LeadSource::IgLeadAd)->sole();
        $this->assertSame(['Rhea Joshi', '+919812345602', 'Play Pass Enquiry'], [$ig->name, $ig->phone, $ig->form_name]);

        $messenger = Lead::where('source', LeadSource::Messenger)->sole();
        $this->assertSame(['Rohit Verma', '6100000000000001'], [$messenger->name, $messenger->contact_id]);
        $this->assertSame('messenger', $messenger->messages->sole()->channel);

        $igDm = Lead::where('source', LeadSource::IgDm)->sole();
        $this->assertSame('Simran Kaur', $igDm->name);
        $this->assertSame('instagram', $igDm->messages->sole()->channel);

        // WhatsApp text + document from the same number -> one lead, two messages (dedupe by phone).
        $wa = Lead::where('source', LeadSource::WhatsappCustomer)->sole();
        $this->assertSame(['Amit Shah', '+919812345603'], [$wa->name, $wa->phone]);
        $this->assertCount(2, $wa->messages);
        $doc = $wa->messages->firstWhere('external_message_id', 'wamid.MOCK_CUSTOMER_DOC_0001');
        $this->assertSame('Guest list for the party', $doc->body);
        Storage::disk('local')->assertExists($doc->attachment_url);
        $this->assertTrue($doc->created_at->isToday());

        // Agency messages -> whatsapp_agency leads (parsing details: Phase4AgencyParsingTest).
        $this->assertTrue(Lead::where('source', LeadSource::WhatsappAgency)->where('agency_name', 'Happy Events Co.')->exists());

        [$leads, $messages] = [Lead::count(), Message::count()];
        // 2 lead ads + messenger + IG DM + 1 WhatsApp (2 msgs) + 6 agency (2 text + 4 Excel rows; 1 row deduped into the FB lead)
        $this->assertSame(11, $leads);
        $this->artisan('meta:replay')->assertSuccessful();
        $this->assertSame([$leads, $messages], [Lead::count(), Message::count()]);
    }

    public function test_dedupe_attaches_to_recent_lead_with_same_phone_only_within_window(): void
    {
        $recent = Lead::factory()->create(['source' => LeadSource::FbLeadAd, 'phone' => '9812345603', 'email' => null, 'status' => LeadStatus::Lost]);

        $this->process('whatsapp_text');

        $this->assertSame(1, Lead::count());
        $this->assertCount(1, $recent->fresh()->messages);
        $this->assertSame(LeadStatus::New, $recent->fresh()->status); // closed lead reopened by new inbound message

        // Same phone but last active beyond DEDUPE_DAYS -> new lead.
        $recent->forceFill(['last_activity_at' => now()->subDays(8)])->save();
        $this->process('whatsapp_document');
        $this->assertSame(2, Lead::count());
    }

    public function test_lead_ad_with_duplicate_phone_adds_summary_message_instead_of_new_lead(): void
    {
        $existing = Lead::factory()->create(['phone' => '+919812345601', 'email' => null, 'campaign_name' => null]);

        $this->process('leadgen_fb');
        $this->process('leadgen_fb');

        $this->assertSame(1, Lead::count());
        $existing->refresh();
        $this->assertSame('neha.k@example.com', $existing->email); // blanks filled
        $this->assertSame('Birthday Parties – Dec', $existing->campaign_name);
        $summary = $existing->messages->sole();
        $this->assertSame('lead_ad', $summary->channel);
        $this->assertStringContainsString('Number of kids: 20', $summary->body);
    }

    public function test_live_mode_fetches_lead_and_form_from_graph_api(): void
    {
        config(['leads.mode' => 'live', 'leads.meta.page_access_token' => 'PAGE_TOKEN', 'leads.meta.graph_version' => 'v21.0']);
        Http::fake([
            'graph.facebook.com/v21.0/900000000000001*' => Http::response(json_decode(file_get_contents(base_path('tests/fixtures/meta/graph/900000000000001.json')), true)),
            'graph.facebook.com/v21.0/800000000000001*' => Http::response(['id' => '800000000000001', 'name' => 'Live Form']),
        ]);

        $this->process('leadgen_fb');

        $lead = Lead::sole();
        $this->assertSame(['Neha Kulkarni', 'Live Form'], [$lead->name, $lead->form_name]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '900000000000001') && $r->hasHeader('Authorization', 'Bearer PAGE_TOKEN'));
    }

    public function test_messenger_echoes_and_receipts_are_ignored(): void
    {
        app(MetaWebhookProcessor::class)->process(['object' => 'page', 'entry' => [['id' => 'PAGE1', 'messaging' => [
            ['sender' => ['id' => 'PAGE1'], 'message' => ['mid' => 'm1', 'text' => 'our reply', 'is_echo' => true]],
            ['sender' => ['id' => 'U1'], 'delivery' => ['mids' => ['m1']]],
        ]]]]);

        $this->assertSame(0, Lead::count());
    }

    public function test_attachment_download_respects_branch_access(): void
    {
        $this->process('whatsapp_document');
        $message = Message::whereNotNull('attachment_url')->sole();
        [$a, $b] = Branch::factory()->count(2)->create();
        $message->lead->update(['branch_id' => $a->id]);

        $this->actingAs(User::factory()->admin()->create())->get("/attachments/{$message->id}")
            ->assertOk()->assertDownload('party-guest-list.pdf');
        $this->actingAs(User::factory()->create(['branch_id' => $b->id]))->get("/attachments/{$message->id}")->assertForbidden();
    }
}
