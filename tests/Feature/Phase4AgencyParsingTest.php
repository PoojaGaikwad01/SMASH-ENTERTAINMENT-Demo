<?php

namespace Tests\Feature;

use App\Console\Commands\ReplayMetaFixtures;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Agency\AgencyParser;
use App\Services\Agency\Ai\AiLeadExtractor;
use App\Services\Agency\Ai\NullAiLeadExtractor;
use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4AgencyParsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['leads.agency_parsing' => true, 'leads.agency_numbers' => ['919900000001']]);
    }

    private function process(string $fixture): void
    {
        app(MetaWebhookProcessor::class)->process(ReplayMetaFixtures::load(base_path("tests/fixtures/meta/{$fixture}.json")));
    }

    private function agencyMessage(array $overrides): array
    {
        return $overrides + ['id' => 'wamid.TEST', 'from' => '919900000001', 'agency_name' => 'Test Agency', 'text' => null,
            'attachment_url' => null, 'filename' => null, 'mime' => null, 'created_at' => now()];
    }

    public function test_agency_text_becomes_one_lead_per_phone_keeping_original_text(): void
    {
        $this->process('whatsapp_agency_text');
        $this->process('whatsapp_agency_text'); // idempotent

        $leads = Lead::where('source', LeadSource::WhatsappAgency)->orderBy('id')->get();
        $this->assertSame(['Kavya Iyer', 'Manish Gupta'], $leads->pluck('name')->all());
        $this->assertSame(['+919812345604', '+919812345605'], $leads->pluck('phone')->all());
        $this->assertSame(['Pune', 'Thane'], $leads->pluck('city')->all());
        $this->assertTrue($leads->every(fn ($l) => $l->status === LeadStatus::New && $l->agency_name === 'Happy Events Co.'));
        $this->assertSame(['wamid.MOCK_AGENCY_TEXT_0001#1', 'wamid.MOCK_AGENCY_TEXT_0001#2'], $leads->pluck('external_id')->all());
        // Every lead shows the full original message.
        $this->assertTrue($leads->every(fn ($l) => str_contains($l->messages->sole()->body, 'Both want birthday party packages')));
    }

    public function test_agency_excel_one_lead_per_row_with_review_flags_and_dedupe(): void
    {
        $this->process('leadgen_fb'); // Neha Kulkarni +919812345601 — also a row in the Excel
        $this->process('whatsapp_agency_excel');
        $this->process('whatsapp_agency_excel');

        $agency = Lead::where('source', LeadSource::WhatsappAgency)->orderBy('id')->get()->keyBy('name');
        $this->assertSame(['Sneha Rao', 'Farhan Ali', 'Lata Menon', 'Vikram Sethi'], $agency->keys()->all());
        $this->assertSame(LeadStatus::New, $agency['Sneha Rao']->status);
        $this->assertSame('+919812345607', $agency['Farhan Ali']->phone); // numeric 919812345607 cell
        $this->assertSame('Andheri, Mumbai', $agency['Sneha Rao']->city);
        $this->assertSame(LeadStatus::NeedsReview, $agency['Lata Menon']->status); // no number
        $this->assertNull($agency['Lata Menon']->phone);
        $this->assertSame(LeadStatus::NeedsReview, $agency['Vikram Sethi']->status); // two numbers

        $row = $agency['Sneha Rao']->messages->sole();
        $this->assertStringStartsWith('Row 3 of agency-leads.xlsx:', $row->body);
        $this->assertStringContainsString('No. of Kids: 15', $row->body);
        Storage::disk('local')->assertExists($row->attachment_url);

        // Duplicate row attached to the existing Facebook lead instead of creating a new one.
        $neha = Lead::where('source', LeadSource::FbLeadAd)->sole();
        $this->assertStringContainsString('Neha Kulkarni', $neha->messages->sole()->body);
        $this->assertSame('Happy Events Co.', $neha->agency_name); // blank filled
    }

    public function test_unparseable_text_is_kept_as_one_needs_review_lead(): void
    {
        app(AgencyParser::class)->handle($this->agencyMessage(['text' => 'Customer wants party for 25 kids, call after 6pm']));

        $lead = Lead::sole();
        $this->assertSame([LeadStatus::NeedsReview, 'wamid.TEST', null], [$lead->status, $lead->external_id, $lead->phone]);
        $this->assertSame('Customer wants party for 25 kids, call after 6pm', $lead->messages->sole()->body);
    }

    public function test_csv_with_other_header_names(): void
    {
        Storage::put('media/whatsapp/x/list.csv', "Name,Contact Number,Area\nRiya Sen,9812345611,Bandra\nOm Das,+91 98123 45612,Powai\n");

        app(AgencyParser::class)->handle($this->agencyMessage(['attachment_url' => 'media/whatsapp/x/list.csv', 'filename' => 'list.csv', 'mime' => 'text/csv']));

        $this->assertSame(
            [['Riya Sen', '+919812345611', 'Bandra'], ['Om Das', '+919812345612', 'Powai']],
            Lead::orderBy('id')->get()->map(fn ($l) => [$l->name, $l->phone, $l->city])->all()
        );
    }

    public function test_ai_step_is_skipped_by_default_and_used_when_bound(): void
    {
        $this->assertInstanceOf(NullAiLeadExtractor::class, app(AiLeadExtractor::class));

        $this->app->bind(AiLeadExtractor::class, fn () => new class implements AiLeadExtractor {
            public function extract(string $text): ?array
            {
                return [['name' => 'AI Person', 'phone' => '98123 45699', 'city' => 'Pune']];
            }
        });

        app(AgencyParser::class)->handle($this->agencyMessage(['text' => 'pls call the lady from yesterday, nine eight one two…']));

        $lead = Lead::sole();
        $this->assertSame(['AI Person', '+919812345699', LeadStatus::New], [$lead->name, $lead->phone, $lead->status]);
    }

    public function test_admin_reviews_and_fixes_a_lead(): void
    {
        $admin = User::factory()->admin()->create();
        $lead = Lead::factory()->create(['source' => LeadSource::WhatsappAgency, 'status' => LeadStatus::NeedsReview, 'phone' => null, 'name' => null]);

        $this->actingAs($admin)->get("/inbox/{$lead->id}?status=needs_review")->assertOk()->assertSee('Save & mark reviewed');

        $this->actingAs($admin)->patch("/leads/{$lead->id}", ['name' => 'Fixed', 'phone' => '12345'])->assertSessionHasErrors('phone');

        $this->actingAs($admin)->patch("/leads/{$lead->id}", ['name' => 'Fixed Name'])->assertSessionHasNoErrors();
        $this->assertSame(LeadStatus::NeedsReview, $lead->fresh()->status); // still no phone

        $this->actingAs($admin)->patch("/leads/{$lead->id}", ['name' => 'Fixed Name', 'phone' => '098123 45690', 'city' => 'Pune', 'agency_name' => 'PartyWala'])
            ->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame(['Fixed Name', '+919812345690', 'Pune', 'PartyWala', LeadStatus::New], [$lead->name, $lead->phone, $lead->city, $lead->agency_name, $lead->status]);

        // Outlet staff may edit their own outlet's enquiries, not other outlets'.
        $manager = User::factory()->create();
        $otherDesk = User::factory()->frontDesk()->create();
        $lead->update(['branch_id' => $manager->branch_id]);
        $this->actingAs($manager)->get("/inbox/{$lead->id}")->assertOk()->assertSee('Edit enquiry details');
        $this->actingAs($manager)->patch("/leads/{$lead->id}", ['name' => 'By manager'])->assertSessionHasNoErrors();
        $this->assertSame('By manager', $lead->fresh()->name);
        $this->actingAs($otherDesk)->patch("/leads/{$lead->id}", ['name' => 'x'])->assertForbidden();
    }
}
