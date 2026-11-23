<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmashPhase3PipelineTest extends TestCase
{
    use RefreshDatabase;

    private Branch $outlet;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Branch::factory()->create();
        $this->desk = User::factory()->frontDesk()->create(['branch_id' => $this->outlet->id, 'name' => 'Desk Dev']);
        config(['leads.follow_up_default_hours' => 24]);
    }

    private function enquiry(array $attrs = []): Lead
    {
        return Lead::factory()->create($attrs + ['branch_id' => $this->outlet->id, 'source' => LeadSource::PhoneCall]);
    }

    // --- Status buttons ---

    public function test_status_change_is_logged_with_user_and_lost_needs_reason(): void
    {
        $lead = $this->enquiry(['next_follow_up_at' => now()->addDay()]);

        $this->actingAs($this->desk)->post("/leads/{$lead->id}/status", ['status' => 'quote_sent'])->assertSessionHasNoErrors();
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/status", ['status' => 'lost'])->assertSessionHasErrors('lost_reason');
        $this->assertSame(LeadStatus::QuoteSent, $lead->fresh()->status);

        $this->actingAs($this->desk)->post("/leads/{$lead->id}/status", ['status' => 'lost', 'lost_reason' => 'Price too high'])->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame([LeadStatus::Lost, 'Price too high', null], [$lead->status, $lead->lost_reason, $lead->next_follow_up_at]);

        $log = $lead->activities()->where('type', ActivityType::StatusChange)->get();
        $this->assertSame(['New → Quote sent', 'Quote sent → Lost · Reason: Price too high'], $log->pluck('body')->all());
        $this->assertSame([$this->desk->id], $log->pluck('user_id')->unique()->values()->all());

        // Reopen clears the reason; invalid / legacy statuses are rejected.
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/status", ['status' => 'contacted']);
        $this->assertNull($lead->fresh()->lost_reason);
        $this->assertNotNull($lead->fresh()->replied_at);
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/status", ['status' => 'needs_review'])->assertSessionHasErrors('status');
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/status", ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    public function test_only_admin_or_own_outlet_staff_can_change_pipeline(): void
    {
        $lead = $this->enquiry();
        $otherDesk = User::factory()->frontDesk()->create();

        $this->actingAs($otherDesk)->post("/leads/{$lead->id}/status", ['status' => 'contacted'])->assertForbidden();
        $this->actingAs($otherDesk)->post("/leads/{$lead->id}/activities", ['type' => 'note', 'body' => 'x'])->assertForbidden();
        $this->actingAs($otherDesk)->patch("/leads/{$lead->id}/follow-up", ['next_follow_up_at' => null])->assertForbidden();

        $unassigned = Lead::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->post("/leads/{$unassigned->id}/status", ['status' => 'contacted'])->assertSessionHasNoErrors();
        $this->assertSame(LeadStatus::Contacted, $unassigned->fresh()->status);
    }

    // --- Call / note log + follow-up ---

    public function test_log_call_moves_new_to_contacted_and_sets_follow_up(): void
    {
        $lead = $this->enquiry();
        $when = now()->addHours(30)->format('Y-m-d\TH:i');

        $this->actingAs($this->desk)->post("/leads/{$lead->id}/activities", [
            'type' => 'call', 'body' => 'Shared prices', 'next_follow_up_at' => $when,
        ])->assertSessionHas('status', 'Call logged.');

        $lead->refresh();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame($when, $lead->next_follow_up_at->format('Y-m-d\TH:i'));
        $this->assertSame(
            [['call', 'Shared prices'], ['status_change', 'New → Contacted']],
            $lead->activities->map(fn ($a) => [$a->type->value, $a->body])->all(),
        );

        // A call on a contacted enquiry doesn't change status; "no follow-up" clears it.
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/activities", ['type' => 'call', 'no_follow_up' => 1]);
        $lead->refresh();
        $this->assertSame([LeadStatus::Contacted, null, 3], [$lead->status, $lead->next_follow_up_at, $lead->activities()->count()]);
    }

    public function test_note_validation_and_follow_up_route(): void
    {
        $lead = $this->enquiry(['status' => LeadStatus::Contacted]);

        $this->actingAs($this->desk)->post("/leads/{$lead->id}/activities", ['type' => 'note'])->assertSessionHasErrors('body');
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/activities", ['type' => 'status_change', 'body' => 'x'])->assertSessionHasErrors('type');
        $this->actingAs($this->desk)->post("/leads/{$lead->id}/activities", ['type' => 'note', 'body' => 'x', 'next_follow_up_at' => now()->subDay()->toDateTimeString()])
            ->assertSessionHasErrors('next_follow_up_at');

        $this->actingAs($this->desk)->post("/leads/{$lead->id}/activities", ['type' => 'note', 'body' => 'Wants Saturday slot'])->assertSessionHasNoErrors();
        $note = $lead->activities()->sole();
        $this->assertSame([ActivityType::Note, 'Wants Saturday slot', $this->desk->id], [$note->type, $note->body, $note->user_id]);
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);

        $this->actingAs($this->desk)->patch("/leads/{$lead->id}/follow-up", ['next_follow_up_at' => now()->addDays(2)->format('Y-m-d\TH:i')])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Follow-up set for'));
        $this->assertTrue($lead->fresh()->next_follow_up_at->isFuture());
        $this->actingAs($this->desk)->patch("/leads/{$lead->id}/follow-up", ['next_follow_up_at' => ''])->assertSessionHas('status', 'Follow-up cleared.');
        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    public function test_every_status_change_is_logged_including_system_ones(): void
    {
        // Funky Monkey paths: mark replied, admin review fix, webhook reopening a lost enquiry.
        $lead = $this->enquiry(['source' => LeadSource::WhatsappCustomer, 'phone' => '9812345678']);
        $lead->markReplied();
        $lead->update(['status' => LeadStatus::Lost]);
        auth()->logout();
        app(LeadIngestor::class)->store($lead->fresh(), ['phone' => '9812345678'], null); // system: reopens

        $log = $lead->activities()->where('type', ActivityType::StatusChange)->get();
        $this->assertSame(['New → Contacted', 'Contacted → Lost', 'Lost → New'], $log->pluck('body')->all());
        $this->assertNull($log->last()->user_id); // system

        $this->actingAs($this->desk)->get("/inbox/{$lead->id}")->assertOk()
            ->assertSeeInOrder(['New → Contacted', 'Contacted → Lost', 'Lost → New'])->assertSee('System');
    }

    // --- Inbox filters + follow-ups due ---

    public function test_inbox_filters_by_type_and_received_date(): void
    {
        $birthday = $this->enquiry(['name' => 'Birthday Bina', 'enquiry_type' => EnquiryType::Birthday, 'created_at' => now()->subDays(2)]);
        $corporate = $this->enquiry(['name' => 'Corporate Chirag', 'enquiry_type' => EnquiryType::Corporate, 'created_at' => now()->subDays(10)]);

        $this->actingAs($this->desk)->get('/inbox?enquiry_type=birthday')->assertSee('Birthday Bina')->assertDontSee('Corporate Chirag');
        $this->actingAs($this->desk)->get('/inbox?from='.now()->subDays(12)->toDateString().'&to='.now()->subDays(5)->toDateString())
            ->assertSee('Corporate Chirag')->assertDontSee('Birthday Bina');
        $this->actingAs($this->desk)->get('/inbox?from=not-a-date')->assertOk()->assertSee('Birthday Bina');
        $this->actingAs($this->desk)->get('/inbox?source=phone_call&status=new&enquiry_type=corporate')->assertSee('Corporate Chirag');
    }

    public function test_follow_ups_due_view_includes_overdue_and_today_only(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $overdue = $this->enquiry(['name' => 'Overdue Om', 'next_follow_up_at' => now()->subDay()]);
        $today = $this->enquiry(['name' => 'Today Tara', 'next_follow_up_at' => now()->addHours(5)]);
        $this->enquiry(['name' => 'Tomorrow Tanvi', 'next_follow_up_at' => now()->addDay()]);
        $this->enquiry(['name' => 'Lost Lalit', 'status' => LeadStatus::Lost, 'next_follow_up_at' => now()->subHour()]);
        Lead::factory()->create(['name' => 'Other Outlet Ojas', 'branch_id' => Branch::factory()->create()->id, 'next_follow_up_at' => now()->subHour()]);

        $res = $this->actingAs($this->desk)->get('/inbox?view=due')->assertOk()->assertSee('Follow-ups due');
        $this->assertSame([$overdue->id, $today->id], $res->viewData('leads')->pluck('id')->all()); // soonest first
        $res->assertDontSee('Tomorrow Tanvi')->assertDontSee('Lost Lalit')->assertDontSee('Other Outlet Ojas');

        // Nav badge shows the same count; status chips stay inside the view.
        $this->actingAs($this->desk)->get('/inbox')->assertSeeInOrder(['Follow-ups', '2']);
        $this->assertSame(1, $this->actingAs($this->desk)->get('/inbox?view=due&q=Tara')->viewData('leads')->count());
        $this->assertSame(3, $this->actingAs(User::factory()->admin()->create())->get('/inbox?view=due')->viewData('leads')->count());
    }

    public function test_detail_page_shows_pipeline_timeline_and_edit_fields(): void
    {
        $lead = $this->enquiry(['status' => LeadStatus::Lost, 'lost_reason' => 'Plans cancelled', 'enquiry_type' => EnquiryType::SchoolGroup]);
        $lead->logActivity(ActivityType::Call, 'Called the principal', $this->desk);
        $lead->logActivity(ActivityType::Note, 'Prefers a weekday', $this->desk);

        $this->actingAs($this->desk)->get("/inbox/{$lead->id}")->assertOk()
            ->assertSee('Lost reason')->assertSee('Plans cancelled')
            ->assertSee('Call logged')->assertSee('Called the principal')->assertSee('Prefers a weekday')
            ->assertSee(route('leads.status', $lead))->assertSee(route('leads.activities.store', $lead))->assertSee(route('leads.follow-up', $lead));

        $this->actingAs($this->desk)->patch("/leads/{$lead->id}", ['name' => 'School X', 'enquiry_type' => 'corporate', 'guest_count' => 45, 'preferred_date' => '2026-12-20', 'notes' => 'Bus parking needed'])
            ->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame([EnquiryType::Corporate, 45, '2026-12-20', 'Bus parking needed'], [$lead->enquiry_type, $lead->guest_count, $lead->preferred_date->toDateString(), $lead->notes]);
    }
}
