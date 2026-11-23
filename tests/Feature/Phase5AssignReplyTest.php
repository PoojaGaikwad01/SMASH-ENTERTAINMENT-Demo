<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase5AssignReplyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function chatLead(string $channel = 'whatsapp', int $hoursAgo = 1, array $attrs = []): Lead
    {
        $lead = Lead::factory()->create($attrs + [
            'source' => match ($channel) { 'messenger' => LeadSource::Messenger, 'instagram' => LeadSource::IgDm, default => LeadSource::WhatsappCustomer },
            'contact_id' => $channel === 'whatsapp' ? '919812345603' : 'PSID-1',
            'phone' => '+919812345603',
            'branch_id' => $this->branch->id,
            'status' => LeadStatus::New,
        ]);
        Message::create(['lead_id' => $lead->id, 'direction' => 'in', 'channel' => $channel, 'body' => 'Hi', 'created_at' => now()->subHours($hoursAgo)]);

        return $lead;
    }

    public function test_admin_assigns_and_reassigns_a_lead(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Contacted, 'replied_at' => now()]);
        $other = Branch::factory()->create();

        $this->actingAs($this->admin)->post("/leads/{$lead->id}/assign", ['branch_id' => $other->id])->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame([$other->id, $this->admin->id, LeadStatus::Contacted, null], [$lead->branch_id, $lead->assigned_by, $lead->status, $lead->replied_at]);
        $this->assertTrue($lead->assigned_at->isToday());

        $this->actingAs($this->manager)->post("/leads/{$lead->id}/assign", ['branch_id' => $this->branch->id])->assertForbidden();
    }

    public function test_assign_rejects_inactive_branch_and_needs_review_leads(): void
    {
        $inactive = Branch::factory()->create(['is_active' => false]);
        $lead = Lead::factory()->create();
        $review = Lead::factory()->create(['status' => LeadStatus::NeedsReview]);

        $this->actingAs($this->admin)->post("/leads/{$lead->id}/assign", ['branch_id' => $inactive->id])->assertSessionHasErrors('branch_id');
        $this->actingAs($this->admin)->post("/leads/{$review->id}/assign", ['branch_id' => $this->branch->id])->assertSessionHasErrors('branch_id');
        $this->assertNull($review->fresh()->branch_id);
    }

    public function test_bulk_assign_skips_needs_review(): void
    {
        $leads = Lead::factory()->count(2)->create();
        $review = Lead::factory()->create(['status' => LeadStatus::NeedsReview]);

        $this->actingAs($this->admin)
            ->post('/leads/bulk-assign', ['ids' => [...$leads->pluck('id'), $review->id], 'branch_id' => $this->branch->id])
            ->assertSessionHas('status', "2 enquiry(s) assigned to {$this->branch->name}. 1 skipped (need review).");

        $this->assertSame(2, Lead::where('branch_id', $this->branch->id)->where('status', LeadStatus::New)->count());
        $this->actingAs($this->manager)->post('/leads/bulk-assign', ['ids' => [$review->id], 'branch_id' => $this->branch->id])->assertForbidden();
    }

    public function test_manager_replies_inside_window_in_mock_mode_without_api_calls(): void
    {
        Http::fake();
        $lead = $this->chatLead('whatsapp', hoursAgo: 2);

        $this->actingAs($this->manager)->get("/inbox/{$lead->id}")->assertOk()->assertSee('Reply via WhatsApp')->assertSee('Log call');
        $this->actingAs($this->manager)->post("/leads/{$lead->id}/reply", ['body' => 'Hello from Smash!'])->assertSessionHasNoErrors();

        $out = $lead->messages()->where('direction', 'out')->sole();
        $this->assertSame(['whatsapp', 'Hello from Smash!', $this->manager->id], [$out->channel, $out->body, $out->sent_by_user_id]);
        $this->assertStringStartsWith('mock.out.', $out->external_message_id);
        $lead->refresh();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertNotNull($lead->replied_at);
        Http::assertNothingSent();
    }

    public function test_reply_blocked_when_24h_window_closed(): void
    {
        $lead = $this->chatLead('messenger', hoursAgo: 25);

        $this->actingAs($this->manager)->get("/inbox/{$lead->id}")->assertOk()->assertSee('24h window closed');
        $this->actingAs($this->manager)->post("/leads/{$lead->id}/reply", ['body' => 'Hi'])->assertSessionHasErrors('body');
        $this->assertSame(0, $lead->messages()->where('direction', 'out')->count());
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_agency_and_lead_ad_messages_never_open_the_window(): void
    {
        $lead = Lead::factory()->create(['source' => LeadSource::WhatsappAgency, 'branch_id' => $this->branch->id, 'status' => LeadStatus::New, 'phone' => '9812345604']);
        Message::create(['lead_id' => $lead->id, 'direction' => 'in', 'channel' => 'agency', 'body' => 'Name: X']);
        Message::create(['lead_id' => $lead->id, 'direction' => 'in', 'channel' => 'lead_ad', 'body' => 'Form']);

        $this->assertFalse($lead->replyWindow()['open']);
        $this->actingAs($this->manager)->post("/leads/{$lead->id}/reply", ['body' => 'Hi'])->assertSessionHasErrors('body');
    }

    public function test_lead_ad_shows_call_whatsapp_and_mark_reply_sent(): void
    {
        $lead = Lead::factory()->create(['source' => LeadSource::FbLeadAd, 'name' => 'Neha Kulkarni', 'phone' => '9812345601', 'branch_id' => $this->branch->id, 'status' => LeadStatus::New]);

        $this->actingAs($this->manager)->get("/inbox/{$lead->id}")->assertOk()
            ->assertSee('href="tel:+919812345601"', false)
            ->assertSee('https://wa.me/919812345601?text=Hi%20Neha', false)
            ->assertSee('Log call')
            ->assertDontSee(route('leads.reply', $lead)); // no chat composer for lead ads

        $this->actingAs($this->manager)->post("/leads/{$lead->id}/mark-replied")->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame(0, $lead->messages()->count()); // logged on the timeline, not as a chat message
        $this->assertSame(['message', 'status_change'], $lead->activities->pluck('type')->map->value->sort()->values()->all());

        $firstReply = $lead->replied_at;
        $this->travel(10)->minutes();
        $this->actingAs($this->manager)->post("/leads/{$lead->id}/mark-replied");
        $this->assertEquals($firstReply, $lead->fresh()->replied_at); // keeps the first reply time

        $this->actingAs($this->manager)->post("/leads/{$lead->id}/status", ['status' => 'lost', 'lost_reason' => 'Chose another venue']);
        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
    }

    public function test_reply_permissions(): void
    {
        $lead = $this->chatLead();
        $otherManager = User::factory()->create();
        $unassigned = Lead::factory()->create();

        $this->actingAs($otherManager)->post("/leads/{$lead->id}/reply", ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($otherManager)->post("/leads/{$lead->id}/mark-replied")->assertForbidden();
        $this->actingAs($this->admin)->post("/leads/{$unassigned->id}/mark-replied")->assertForbidden();
        $this->actingAs($this->admin)->get("/inbox/{$unassigned->id}")->assertSee('Assign this enquiry to an outlet');
    }

    public function test_live_mode_sends_via_whatsapp_and_messenger_apis(): void
    {
        config(['leads.mode' => 'live', 'leads.meta.graph_version' => 'v21.0', 'leads.whatsapp.phone_number_id' => 'PNID',
            'leads.whatsapp.access_token' => 'WA_TOKEN', 'leads.meta.page_access_token' => 'PAGE_TOKEN']);
        Http::fake([
            'graph.facebook.com/v21.0/PNID/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]]),
            'graph.facebook.com/v21.0/me/messages' => Http::response(['recipient_id' => 'PSID-1', 'message_id' => 'm_OUT2']),
        ]);

        $wa = $this->chatLead('whatsapp');
        $this->actingAs($this->manager)->post("/leads/{$wa->id}/reply", ['body' => 'WA reply'])->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v21.0/PNID/messages'
            && $r['to'] === '919812345603' && $r['text']['body'] === 'WA reply' && $r->hasHeader('Authorization', 'Bearer WA_TOKEN'));
        $this->assertTrue(Message::where('external_message_id', 'wamid.OUT1')->exists());

        $fb = $this->chatLead('messenger', attrs: ['phone' => null]);
        $this->actingAs($this->manager)->post("/leads/{$fb->id}/reply", ['body' => 'FB reply'])->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/me/messages') && $r['recipient']['id'] === 'PSID-1' && $r['messaging_type'] === 'RESPONSE');
        $this->assertTrue(Message::where('external_message_id', 'm_OUT2')->exists());
    }

    public function test_api_failure_keeps_lead_pending_and_shows_error(): void
    {
        config(['leads.mode' => 'live', 'leads.whatsapp.phone_number_id' => 'PNID']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Re-engagement message']], 400)]);
        $lead = $this->chatLead();

        $this->actingAs($this->manager)->post("/leads/{$lead->id}/reply", ['body' => 'Hi'])->assertSessionHasErrors('body');

        $this->assertSame(0, $lead->messages()->where('direction', 'out')->count());
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }
}
