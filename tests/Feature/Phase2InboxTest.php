<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2InboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_30_leads_across_all_sources_with_messages(): void
    {
        $this->seed();

        $this->assertSame(30, Lead::count());
        $this->assertCount(count(LeadSource::options()), Lead::distinct()->pluck('source'));
        $this->assertSame(0, Lead::where('source', LeadSource::WhatsappAgency)->count()); // agency parsing off
        $this->assertSame(0, Lead::where('status', LeadStatus::NeedsReview)->count());
        $this->assertGreaterThan(15, Message::count());
        $this->assertTrue(Lead::whereIn('source', ['phone_call', 'walk_in', 'walk_in_qr', 'website_form'])->whereNull('branch_id')->doesntExist());
        // Chat leads have messages; lead ads don't.
        $this->assertTrue(Lead::where('source', LeadSource::Messenger)->whereDoesntHave('messages')->doesntExist());
        $this->assertTrue(Lead::where('source', LeadSource::FbLeadAd)->whereHas('messages')->doesntExist());
    }

    public function test_admin_sees_all_leads_and_manager_only_own_branch(): void
    {
        [$a, $b] = Branch::factory()->count(2)->create();
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create(['branch_id' => $a->id]);

        $mine = Lead::factory()->create(['name' => 'Mine Lead', 'branch_id' => $a->id, 'status' => LeadStatus::New]);
        $other = Lead::factory()->create(['name' => 'Other Lead', 'branch_id' => $b->id, 'status' => LeadStatus::New]);
        $unassigned = Lead::factory()->create(['name' => 'Floating Lead']);

        $this->actingAs($admin)->get('/inbox')->assertOk()->assertSee(['Mine Lead', 'Other Lead', 'Floating Lead']);

        $this->actingAs($manager)->get('/inbox')->assertOk()->assertSee('Mine Lead')->assertDontSee('Other Lead')->assertDontSee('Floating Lead');
        $this->actingAs($manager)->get("/inbox/{$mine->id}")->assertOk();
        $this->actingAs($manager)->get("/inbox/{$other->id}")->assertForbidden();
        $this->actingAs($manager)->get("/inbox/{$unassigned->id}")->assertForbidden();
        // A manager can't widen scope via the branch filter.
        $this->actingAs($manager)->get("/inbox?branch_id={$b->id}")->assertDontSee('Other Lead');
    }

    public function test_filters_and_search(): void
    {
        $admin = User::factory()->admin()->create();
        $branch = Branch::factory()->create();
        Lead::factory()->create(['name' => 'Priya WA', 'phone' => '9876543210']);
        Lead::factory()->create(['name' => 'Rahul FB', 'source' => LeadSource::FbLeadAd, 'phone' => '9123456780', 'branch_id' => $branch->id, 'status' => LeadStatus::New]);
        Lead::factory()->create(['name' => 'Check Review', 'status' => LeadStatus::NeedsReview, 'phone' => null]);

        $get = fn ($q) => $this->actingAs($admin)->get('/inbox?'.$q)->assertOk();

        $get('source=fb_lead_ad')->assertSee('Rahul FB')->assertDontSee('Priya WA');
        $get('status=needs_review')->assertSee('Check Review')->assertDontSee('Priya WA');
        $get("branch_id={$branch->id}")->assertSee('Rahul FB')->assertDontSee('Priya WA');
        $get('branch_id=none')->assertSee('Priya WA')->assertDontSee('Rahul FB');
        $get('q=priya')->assertSee('Priya WA')->assertDontSee('Rahul FB');
        $get('q=098765+43210')->assertSee('Priya WA')->assertDontSee('Rahul FB'); // phone search ignores formatting
    }

    public function test_list_paginates_25_per_page_sorted_by_latest_activity(): void
    {
        $admin = User::factory()->admin()->create();
        Lead::factory()->count(30)->create(['last_activity_at' => now()->subDay()]);
        $recent = Lead::factory()->create(['name' => 'Zed Recent', 'last_activity_at' => now()->subDays(2)]);
        Message::create(['lead_id' => $recent->id, 'direction' => 'in', 'channel' => 'whatsapp', 'body' => 'Hello there']);

        $this->assertTrue($recent->fresh()->last_activity_at->isToday()); // bumped by new message

        $response = $this->actingAs($admin)->get('/inbox')->assertOk();
        $this->assertCount(25, $response->viewData('leads'));
        $this->assertSame($recent->id, $response->viewData('leads')->first()->id);
        $this->assertSame(31, $response->viewData('leads')->total());
    }

    public function test_thread_shows_messages_and_lead_ad_form_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $chat = Lead::factory()->create();
        Message::create(['lead_id' => $chat->id, 'direction' => 'in', 'channel' => 'whatsapp', 'body' => 'Party for 20 kids?']);
        Message::create(['lead_id' => $chat->id, 'direction' => 'out', 'channel' => 'whatsapp', 'body' => 'Yes, sure!', 'sent_by_user_id' => $admin->id]);

        $this->actingAs($admin)->get("/inbox/{$chat->id}")->assertOk()
            ->assertSeeInOrder(['Party for 20 kids?', 'Yes, sure!']);

        $ad = Lead::factory()->create([
            'source' => LeadSource::FbLeadAd, 'form_name' => 'Party Enquiry Form',
            'raw_payload' => ['field_data' => [['name' => 'number_of_kids', 'values' => ['18']]]],
        ]);
        $this->actingAs($admin)->get("/inbox/{$ad->id}")->assertOk()
            ->assertSee('Lead form submitted')->assertSee('Number of kids')->assertSee('18');
    }

    public function test_lead_phone_is_normalized_and_branch_with_leads_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create();
        $lead = Lead::factory()->create(['phone' => '098765 43210', 'branch_id' => $branch->id]);
        $this->assertSame('+919876543210', $lead->phone);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->delete("/branches/{$branch->id}")->assertSessionHasErrors('branch');
    }
}
