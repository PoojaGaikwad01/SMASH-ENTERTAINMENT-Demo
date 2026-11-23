<?php

namespace Tests\Feature;

use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmashPhase2EnquiryChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $outlet;

    private User $desk;

    protected function setUp(): void
    {
        parent::setUp(); // CACHE_STORE=array: rate limits reset per test
        $this->outlet = Branch::factory()->create(['name' => 'Smash Andheri', 'slug' => 'andheri']);
        $this->desk = User::factory()->frontDesk()->create(['branch_id' => $this->outlet->id]);
        config(['leads.public_form.enabled' => true, 'leads.follow_up_default_hours' => 24]);
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'name' => 'Priya Nair', 'phone' => '098200 12345', 'email' => 'priya@example.com',
            'enquiry_type' => 'birthday', 'preferred_date' => now()->addDays(10)->toDateString(),
            'guest_count' => 25, 'notes' => 'Superhero theme',
        ];
    }

    // --- Staff form ---

    public function test_front_desk_creates_phone_call_enquiry_for_own_outlet(): void
    {
        $other = Branch::factory()->create();
        $this->actingAs($this->desk)->get('/enquiries/create')->assertOk()->assertSee('Phone call')->assertSee('Smash Andheri');

        $res = $this->actingAs($this->desk)->post('/enquiries', $this->payload(['source' => 'phone_call', 'branch_id' => $other->id]));

        $lead = Lead::sole();
        $res->assertRedirect(route('inbox.show', $lead))->assertSessionHas('status', 'Enquiry created.');
        $this->assertSame(
            [LeadSource::PhoneCall, '+919820012345', $this->outlet->id, $this->desk->id, EnquiryType::Birthday, 25, LeadStatus::New],
            [$lead->source, $lead->phone, $lead->branch_id, $lead->created_by_user_id, $lead->enquiry_type, $lead->guest_count, $lead->status],
        ); // other outlet ignored for staff
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $lead->next_follow_up_at->timestamp, 5);
        $this->assertFalse($lead->awaitingReply()); // staff already spoke to the customer
        $this->actingAs($this->desk)->get("/inbox/{$lead->id}")->assertOk()->assertSee('Enquiry via Phone call')->assertSee('Superhero theme');
    }

    public function test_admin_must_choose_outlet_and_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/enquiries', $this->payload(['source' => 'walk_in']))->assertSessionHasErrors('branch_id');
        $this->actingAs($admin)->post('/enquiries', $this->payload(['source' => 'walk_in', 'branch_id' => $this->outlet->id, 'phone' => '12345']))->assertSessionHasErrors('phone');
        $this->actingAs($admin)->post('/enquiries', $this->payload(['source' => 'messenger', 'branch_id' => $this->outlet->id]))->assertSessionHasErrors('source');
        $this->actingAs($admin)->post('/enquiries', $this->payload(['source' => 'walk_in', 'branch_id' => $this->outlet->id, 'preferred_date' => now()->subDay()->toDateString()]))->assertSessionHasErrors('preferred_date');

        $this->actingAs($admin)->post('/enquiries', $this->payload(['source' => 'walk_in', 'branch_id' => $this->outlet->id]))->assertSessionHasNoErrors();
        $this->assertSame([LeadSource::WalkIn, $this->outlet->id], [Lead::sole()->source, Lead::sole()->branch_id]);
    }

    public function test_dedupes_into_open_enquiry_but_not_lost_or_other_outlet(): void
    {
        $open = Lead::factory()->create(['phone' => '9820012345', 'branch_id' => null, 'email' => null, 'guest_count' => null,
            'status' => LeadStatus::Contacted, 'source' => LeadSource::FbLeadAd]);

        $res = $this->actingAs($this->desk)->post('/enquiries', $this->payload(['source' => 'walk_in', 'phone' => '+91 98200-12345']));
        $res->assertRedirect(route('inbox.show', $open))->assertSessionHas('status', fn ($s) => str_contains($s, 'already has an open enquiry'));

        $open->refresh();
        $this->assertSame(1, Lead::count());
        $this->assertSame(['priya@example.com', 25, $this->outlet->id, LeadStatus::Contacted, EnquiryType::Birthday],
            [$open->email, $open->guest_count, $open->branch_id, $open->status, $open->enquiry_type]);
        $note = $open->activities()->sole();
        $this->assertSame([\App\Enums\ActivityType::Note, $this->desk->id], [$note->type, $note->user_id]);
        $this->assertStringStartsWith('Repeat enquiry via Walk-in: Birthday party', $note->body);
        $this->assertSame(0, $open->messages()->count());

        // Lost enquiries are not reused.
        $open->update(['status' => LeadStatus::Lost]);
        $this->actingAs($this->desk)->post('/enquiries', $this->payload(['source' => 'walk_in']));
        $this->assertSame(2, Lead::count());

        // An open enquiry at ANOTHER outlet is not merged into (staff couldn't see it).
        Lead::query()->delete();
        Lead::factory()->create(['phone' => '9820012345', 'branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($this->desk)->post('/enquiries', $this->payload(['source' => 'walk_in']));
        $this->assertSame(2, Lead::count());
    }

    // --- Public walk-in (QR) form ---

    public function test_public_form_creates_walk_in_qr_enquiry_without_login(): void
    {
        $this->get('/enquiry/andheri')->assertOk()->assertSee('Smash Andheri')->assertSee('name="website"', false);

        $this->post('/enquiry/andheri', $this->payload())->assertRedirect('/enquiry/andheri')->assertSessionHas('submitted');
        $this->get('/enquiry/andheri')->assertSee('Thank you!');

        $lead = Lead::sole();
        $this->assertSame([LeadSource::WalkInQr, $this->outlet->id, null, '+919820012345'], [$lead->source, $lead->branch_id, $lead->created_by_user_id, $lead->phone]);
        $this->assertTrue($lead->awaitingReply()); // nobody has responded yet -> reminders apply

        $this->post('/enquiry/andheri', $this->payload(['phone' => 'abc']))->assertSessionHasErrors('phone');
    }

    public function test_public_form_honeypot_rate_limit_and_availability(): void
    {
        $this->post('/enquiry/andheri', $this->payload(['website' => 'http://spam.example']))->assertSessionHas('submitted');
        $this->assertSame(0, Lead::count()); // fake success, nothing stored

        for ($i = 0; $i < 4; $i++) {
            $this->post('/enquiry/andheri', $this->payload(['phone' => '98200'.sprintf('%05d', $i)]));
        }
        $this->post('/enquiry/andheri', $this->payload())->assertStatus(429);

        $this->get('/enquiry/unknown')->assertNotFound();
        $this->outlet->update(['is_active' => false]);
        $this->get('/enquiry/andheri')->assertNotFound();
        $this->outlet->update(['is_active' => true]);
        config(['leads.public_form.enabled' => false]);
        $this->get('/enquiry/andheri')->assertNotFound();
    }

    public function test_qr_poster_for_admin_and_own_outlet_staff_only(): void
    {
        $other = Branch::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->get("/branches/{$this->outlet->id}/qr")->assertRedirect('/login');

        $this->actingAs($admin)->get("/branches/{$other->id}/qr")->assertOk()->assertSee('<svg', false)->assertSee(route('public-enquiry.show', $other->slug));
        $this->actingAs($this->desk)->get("/branches/{$this->outlet->id}/qr")->assertOk()->assertSee('/enquiry/andheri');
        $this->actingAs($this->desk)->get("/branches/{$other->id}/qr")->assertForbidden();
    }

    // --- Website API ---

    public function test_website_api_creates_enquiry_with_optional_outlet(): void
    {
        $this->postJson('/api/enquiries', $this->payload(['phone' => 9820012345, 'outlet' => 'andheri']))
            ->assertCreated()->assertJson(['ok' => true])->assertJsonMissingPath('id');
        $this->postJson('/api/enquiries', $this->payload(['phone' => '9876543210']))->assertCreated();

        $this->assertSame(2, Lead::where('source', LeadSource::WebsiteForm)->count());
        $this->assertSame($this->outlet->id, Lead::where('phone', '+919820012345')->value('branch_id'));
        $this->assertNull(Lead::where('phone', '+919876543210')->value('branch_id')); // admin assigns

        // Same phone again while open -> merged, response identical (no enumeration).
        $this->postJson('/api/enquiries', $this->payload(['phone' => '9876543210', 'notes' => 'Any update?']))->assertCreated();
        $this->assertSame(2, Lead::count());

        $this->postJson('/api/enquiries', ['name' => '', 'phone' => '1'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone']);
        $this->postJson('/api/enquiries', $this->payload(['outlet' => 'nope']))->assertJsonValidationErrors('outlet');
    }

    public function test_website_api_honeypot_throttle_and_cors(): void
    {
        config(['cors.allowed_origins' => ['https://www.smash.example'], 'leads.public_form.enabled' => false]);
        $this->postJson('/api/enquiries', $this->payload())->assertNotFound(); // 1st hit this minute
        config(['leads.public_form.enabled' => true]);

        $this->postJson('/api/enquiries', $this->payload(['website' => 'x']))->assertCreated();
        $this->assertSame(0, Lead::count());

        $this->withHeaders(['Origin' => 'https://www.smash.example'])->postJson('/api/enquiries', $this->payload())
            ->assertCreated()->assertHeader('Access-Control-Allow-Origin', 'https://www.smash.example');
        // Single allowed origin: it is always echoed as-is, so the browser blocks any other origin.
        $evil = $this->withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/enquiries', $this->payload());
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->call('OPTIONS', '/api/enquiries', [], [], [], [
            'HTTP_ORIGIN' => 'https://www.smash.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://www.smash.example');

        $this->postJson('/api/enquiries', $this->payload())->assertCreated(); // 5th of 5 this minute (preflight isn't throttled)
        $this->postJson('/api/enquiries', $this->payload())->assertStatus(429);
    }

    // --- Meta sources map into the same enquiry model ---

    public function test_lead_ad_answers_fill_enquiry_fields(): void
    {
        config(['leads.agency_parsing' => false]);
        $this->artisan('meta:replay', ['fixtures' => ['leadgen_fb', 'leadgen_ig']])->assertSuccessful();

        $fb = Lead::where('source', LeadSource::FbLeadAd)->sole();
        $this->assertSame([EnquiryType::Birthday, 20], [$fb->enquiry_type, $fb->guest_count]); // "Party Enquiry Form", number_of_kids
        $this->assertSame(EnquiryType::Tickets, Lead::where('source', LeadSource::IgLeadAd)->sole()->enquiry_type); // "Play Pass Enquiry"
    }

    public function test_enquiry_type_guess(): void
    {
        $this->assertSame(EnquiryType::Corporate, EnquiryType::guess('Corporate team outing'));
        $this->assertSame(EnquiryType::SchoolGroup, EnquiryType::guess('school_group'));
        $this->assertNull(EnquiryType::guess('hello'));
    }
}
