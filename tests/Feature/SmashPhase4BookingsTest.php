<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\BookingStatus;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Models\Addon;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Package;
use App\Models\User;
use App\Services\BookingService;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmashPhase4BookingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $outlet;

    private User $manager;

    private User $desk;

    private Lead $lead;

    private Area $room;

    private Area $arena;

    private Package $package;

    private Addon $decor;

    private Addon $cake;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTime(10, 0));
        $this->day = today()->addDays(3)->toDateString();

        $this->outlet = Branch::factory()->create(['name' => 'Smash Andheri']);
        $this->manager = User::factory()->create(['branch_id' => $this->outlet->id]); // outlet manager
        $this->desk = User::factory()->frontDesk()->create(['branch_id' => $this->outlet->id]);
        $o = ['outlet_id' => $this->outlet->id];
        $this->room = Area::create($o + ['name' => 'Party Room A', 'capacity' => 30]);
        $this->arena = Area::create($o + ['name' => 'Event Arena', 'capacity' => 100]);
        $this->package = Package::create($o + ['name' => 'Birthday Basic', 'price' => 14999, 'duration_minutes' => 120, 'min_guests' => 10, 'max_guests' => 20]);
        $this->decor = Addon::create($o + ['name' => 'Theme decoration', 'price' => 3500]);
        $this->cake = Addon::create($o + ['name' => 'Custom cake', 'price' => 2800]);
        $this->lead = Lead::factory()->create([
            'name' => 'Priya Nair', 'phone' => '9820012345', 'branch_id' => $this->outlet->id, 'source' => LeadSource::PhoneCall,
            'enquiry_type' => EnquiryType::Birthday, 'guest_count' => 15, 'preferred_date' => $this->day,
        ]);
    }

    private function book(array $over = [], ?Lead $lead = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->desk)->post('/leads/'.($lead ?? $this->lead)->id.'/bookings', $over + [
            'area_id' => $this->room->id, 'package_id' => $this->package->id, 'event_date' => $this->day,
            'start_time' => '16:00', 'guest_count' => 15,
        ]);
    }

    // --- Catalogue ---

    public function test_catalogue_crud_is_scoped_to_the_managers_outlet(): void
    {
        $other = Branch::factory()->create();
        $otherArea = Area::create(['outlet_id' => $other->id, 'name' => 'Pune Room', 'capacity' => 10]);

        $this->actingAs($this->manager)->get('/catalog/areas')->assertOk()->assertSee('Party Room A')->assertDontSee('Pune Room');
        $this->actingAs($this->manager)->get('/inbox')->assertSee('Catalogue');
        $this->actingAs($this->desk)->get('/inbox')->assertDontSee('Catalogue');
        $this->actingAs($this->desk)->get('/catalog/packages')->assertForbidden();
        $this->actingAs($this->manager)->get('/catalog/bogus')->assertNotFound();

        // outlet_id from the request is ignored for managers; names are unique per outlet.
        $this->actingAs($this->manager)->post('/catalog/areas', ['name' => 'VR Zone', 'capacity' => '', 'outlet_id' => $other->id, 'is_active' => 1])
            ->assertRedirect('/catalog/areas');
        $this->assertSame([$this->outlet->id, 0, true], [Area::where('name', 'VR Zone')->value('outlet_id'), Area::where('name', 'VR Zone')->value('capacity'), (bool) Area::where('name', 'VR Zone')->value('is_active')]);
        $this->actingAs($this->manager)->post('/catalog/areas', ['name' => 'VR Zone'])->assertSessionHasErrors('name');
        $this->actingAs($this->manager)->get("/catalog/areas/{$otherArea->id}/edit")->assertNotFound();

        $this->actingAs($this->manager)->put("/catalog/packages/{$this->package->id}", [
            'name' => 'Birthday Basic', 'price' => 15999, 'duration_minutes' => 150, 'min_guests' => 10, 'max_guests' => 5,
        ])->assertSessionHasErrors('max_guests');
        $this->actingAs($this->manager)->put("/catalog/packages/{$this->package->id}", [
            'name' => 'Birthday Basic', 'price' => 15999, 'duration_minutes' => 150, 'min_guests' => 10, 'max_guests' => 20, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['15999.00', 150], [$this->package->fresh()->price, $this->package->fresh()->duration_minutes]);

        // Admin picks the outlet; anything used by a booking can't be deleted.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/catalog/addons', ['name' => 'Balloons', 'price' => 900])->assertSessionHasErrors('outlet_id');
        $this->actingAs($admin)->post('/catalog/addons', ['name' => 'Balloons', 'price' => 900, 'outlet_id' => $other->id, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/catalog/addons')->assertSee('Balloons')->assertSee('Theme decoration');

        $this->book(['addons' => [$this->decor->id => 1]]);
        $this->actingAs($this->manager)->delete("/catalog/areas/{$this->room->id}")->assertSessionHasErrors('item');
        $this->actingAs($this->manager)->delete("/catalog/addons/{$this->decor->id}")->assertSessionHasErrors('item');
        $this->actingAs($this->manager)->delete("/catalog/addons/{$this->cake->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($this->cake);
    }

    // --- Create from enquiry ---

    public function test_booking_from_enquiry_calculates_totals_and_end_time(): void
    {
        $this->actingAs($this->desk)->get("/leads/{$this->lead->id}/bookings/create")->assertOk()
            ->assertSee('Priya Nair')->assertSee('Birthday Basic')->assertSee('Theme decoration')
            ->assertViewHas('booking', fn ($b) => $b->package_id === $this->package->id); // birthday enquiry → birthday package pre-selected

        $res = $this->book(['addons' => [$this->decor->id => 1, $this->cake->id => 2], 'advance_paid' => 5000, 'child_name' => 'Aarav', 'child_age' => 7]);

        $b = Booking::sole();
        $res->assertRedirect(route('bookings.show', $b))->assertSessionHas('status', "Booking {$b->booking_code} created as tentative.");
        $this->assertMatchesRegularExpression('/^SMB-\d{6}-\d{4}$/', $b->booking_code);
        $this->assertSame(
            ['18:00:00', '14999.00', '24099.00', '5000.00', '19099.00', PaymentStatus::Partial, BookingStatus::Tentative, $this->desk->id, $this->outlet->id],
            [$b->end_time, $b->package_price, $b->total_amount, $b->advance_paid, $b->balance_due, $b->payment_status, $b->status, $b->created_by_user_id, $b->outlet_id],
        );
        $this->assertEquals([$this->decor->id => 1, $this->cake->id => 2], $b->addons->pluck('pivot.qty', 'id')->sortKeys()->all());
        $this->assertStringStartsWith("Booking {$b->booking_code} created (tentative): Party Room A", $this->lead->activities()->sole()->body);
        $this->assertSame(LeadStatus::New, $this->lead->fresh()->status); // only confirming changes the enquiry

        $this->actingAs($this->desk)->get("/bookings/{$b->id}")->assertOk()
            ->assertSee($b->booking_code)->assertSee('₹24,099')->assertSee('₹19,099')->assertSee('Aarav')->assertSee('Confirm booking');
        $this->actingAs($this->desk)->get("/inbox/{$this->lead->id}")->assertSee($b->booking_code)->assertSee('+ Create booking');

        // Later catalogue price changes don't touch the agreed price when the booking is edited.
        $this->package->update(['price' => 19999]);
        $this->decor->update(['price' => 9999]);
        $this->actingAs($this->desk)->put("/bookings/{$b->id}", [
            'area_id' => $this->room->id, 'package_id' => $this->package->id, 'event_date' => $this->day, 'start_time' => '15:00',
            'end_time' => '17:30', 'guest_count' => 18, 'addons' => [$this->decor->id => 1, $this->cake->id => 0],
        ])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame(['15:00:00', '17:30:00', '18499.00', '13499.00', [$this->decor->id]], [$b->start_time, $b->end_time, $b->total_amount, $b->balance_due, $b->addons->pluck('id')->all()]);
    }

    // --- Double booking ---

    public function test_overlapping_bookings_in_the_same_area_are_rejected(): void
    {
        $this->book()->assertSessionHasNoErrors(); // 16:00–18:00
        $first = Booking::sole();

        $this->book(['start_time' => '17:00'])->assertSessionHasErrors(['start_time' => "Party Room A is already booked 16:00–18:00 on ".today()->addDays(3)->format('D d M')." ({$first->booking_code})."]);
        $this->book(['start_time' => '15:00', 'package_id' => '', 'end_time' => '16:30'])->assertSessionHasErrors('start_time');
        $this->assertSame(1, Booking::count());

        $this->book(['start_time' => '18:00'])->assertSessionHasNoErrors();                         // back-to-back
        $this->book(['area_id' => $this->arena->id])->assertSessionHasNoErrors();                   // other area
        $this->book(['event_date' => today()->addDays(4)->toDateString()])->assertSessionHasNoErrors(); // other day

        // Editing keeps its own slot; cancelling frees it.
        $this->actingAs($this->desk)->put("/bookings/{$first->id}", ['area_id' => $this->room->id, 'package_id' => $this->package->id,
            'event_date' => $this->day, 'start_time' => '16:00', 'guest_count' => 12])->assertSessionHasNoErrors();
        $this->actingAs($this->desk)->post("/bookings/{$first->id}/status", ['status' => 'cancelled', 'cancel_reason' => 'Changed plans']);
        $this->book(['start_time' => '16:30', 'package_id' => '', 'end_time' => '17:30'])->assertSessionHasNoErrors();

        $clashes = app(BookingService::class)->clashes($this->room->id, $this->day, '17:00:00', '18:30:00');
        $this->assertSame(['16:30–17:30', '18:00–20:00'], $clashes->pluck('time_range')->all());
    }

    // --- Status flow ---

    public function test_status_flow_moves_the_enquiry(): void
    {
        $this->book();
        $b = Booking::sole();

        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'confirmed'])
            ->assertSessionHas('status', "Booking {$b->booking_code} confirmed. Enquiry marked Confirmed. Confirmation queued on WhatsApp + email.");
        $b->refresh();
        $this->assertSame(BookingStatus::Confirmed, $b->status);
        $this->assertNotNull($b->confirmed_at);
        $this->assertSame(LeadStatus::Confirmed, $this->lead->fresh()->status);
        $this->assertSame(
            ["Booking {$b->booking_code} confirmed", 'New → Confirmed'],
            $this->lead->activities()->whereIn('type', [ActivityType::Booking, ActivityType::StatusChange])->pluck('body')->slice(1)->values()->all(),
        );

        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'tentative'])->assertSessionHasErrors('status');
        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame([BookingStatus::Completed, LeadStatus::Completed], [$b->fresh()->status, $this->lead->fresh()->status]);
        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'cancelled', 'cancel_reason' => 'x'])->assertSessionHasErrors('status');
        $this->actingAs($this->desk)->get("/bookings/{$b->id}/edit")->assertRedirect(route('bookings.show', $b));
        $this->actingAs($this->desk)->put("/bookings/{$b->id}", [])->assertStatus(422);

        // Cancel needs a reason; the enquiry is left alone.
        $this->book(['event_date' => today()->addDays(5)->toDateString()]);
        $second = Booking::latest('id')->first();
        $this->actingAs($this->desk)->post("/bookings/{$second->id}/status", ['status' => 'cancelled'])->assertSessionHasErrors('cancel_reason');
        $this->actingAs($this->desk)->post("/bookings/{$second->id}/status", ['status' => 'cancelled', 'cancel_reason' => 'Rescheduling'])->assertSessionHasNoErrors();
        $this->assertSame([BookingStatus::Cancelled, 'Rescheduling', LeadStatus::Completed], [$second->fresh()->status, $second->fresh()->cancel_reason, $this->lead->fresh()->status]);
    }

    public function test_completing_one_of_two_open_bookings_keeps_the_enquiry_confirmed(): void
    {
        $service = app(BookingService::class);
        $this->book();
        $this->book(['event_date' => today()->addDays(6)->toDateString()]);
        [$a, $b] = Booking::orderBy('id')->get()->all();
        $service->transition($a, BookingStatus::Confirmed);
        $service->transition($b, BookingStatus::Confirmed);
        $service->transition($a->fresh(), BookingStatus::Completed);
        $this->assertSame(LeadStatus::Confirmed, $this->lead->fresh()->status);
    }

    // --- Validation + access ---

    public function test_validation_and_outlet_scoping(): void
    {
        $other = Branch::factory()->create();
        $otherArea = Area::create(['outlet_id' => $other->id, 'name' => 'Pune Room', 'capacity' => 50]);

        $this->book(['guest_count' => 25])->assertSessionHasErrors(['guest_count' => 'Birthday Basic is for up to 20 guests.']);
        $this->book(['guest_count' => 40, 'package_id' => '', 'end_time' => '18:00'])->assertSessionHasErrors(['guest_count' => 'Party Room A holds up to 30 guests.']);
        $this->book(['area_id' => $otherArea->id])->assertSessionHasErrors('area_id');
        $this->book(['event_date' => today()->subDay()->toDateString()])->assertSessionHasErrors('event_date');
        $this->book(['package_id' => ''])->assertSessionHasErrors('end_time');
        $this->book(['start_time' => '23:00'])->assertSessionHasErrors('end_time'); // 2 h package would run past midnight
        $this->book(['advance_paid' => 20000])->assertSessionHasErrors('advance_paid');
        $this->book(['addons' => [999 => 1]])->assertSessionHasErrors('addons');
        $this->room->update(['is_active' => false]);
        $this->book()->assertSessionHasErrors('area_id');
        $this->room->update(['is_active' => true]);
        $this->assertSame(0, Booking::count());

        $this->book()->assertSessionHasNoErrors();
        $b = Booking::sole();
        $otherDesk = User::factory()->frontDesk()->create(['branch_id' => $other->id]);
        $this->actingAs($otherDesk)->get("/bookings/{$b->id}")->assertForbidden();
        $this->actingAs($otherDesk)->post("/bookings/{$b->id}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->book([], null, $otherDesk)->assertForbidden();
        $this->actingAs($otherDesk)->get('/bookings')->assertOk()->assertDontSee($b->booking_code);

        $unassigned = Lead::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->get("/leads/{$unassigned->id}/bookings/create")
            ->assertRedirect(route('inbox.show', $unassigned))->assertSessionHasErrors('lead');
    }

    // --- Calendar / lookup / list ---

    public function test_calendar_day_and_week_views(): void
    {
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(10, 0)); // a Wednesday: today and tomorrow share the week
        $service = app(BookingService::class);
        $mk = fn (array $d) => $service->save($this->lead, $d + ['area_id' => $this->room->id, 'package_id' => $this->package->id, 'guest_count' => 12], null, $this->desk);
        $today = $mk(['event_date' => today()->toDateString(), 'start_time' => '11:00']);
        $service->transition($today, BookingStatus::Confirmed);
        $later = $mk(['event_date' => today()->addDay()->toDateString(), 'start_time' => '14:00', 'area_id' => $this->arena->id]);
        $cancelled = $mk(['event_date' => today()->toDateString(), 'start_time' => '15:00']);
        $service->transition($cancelled, BookingStatus::Cancelled, 'No show');

        $this->actingAs($this->desk)->get('/bookings/calendar')->assertOk()
            ->assertSee('Party Room A')->assertSee('Event Arena')->assertSee($today->booking_code)->assertSee('11:00–13:00')
            ->assertDontSee($later->booking_code)->assertDontSee($cancelled->booking_code);
        $this->actingAs($this->desk)->get('/bookings/calendar?view=week&date='.today()->toDateString())->assertOk()
            ->assertSee($today->booking_code, false)->assertSee('14:00–16:00')->assertDontSee($cancelled->booking_code);
        $this->actingAs($this->desk)->get('/bookings/calendar?date=not-a-date')->assertOk();

        $pune = Branch::factory()->create(['name' => 'Smash Pune']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get("/bookings/calendar?outlet={$pune->id}")->assertOk()->assertSee('No areas set up for Smash Pune');
        $this->actingAs($admin)->get("/bookings/calendar?outlet={$this->outlet->id}")->assertSee($today->booking_code);
    }

    public function test_counter_lookup_by_code_or_phone_and_list_filters(): void
    {
        $this->book();
        $b = Booking::sole();
        $past = app(BookingService::class)->save($this->lead, ['area_id' => $this->arena->id, 'event_date' => today()->subDays(3)->toDateString(),
            'start_time' => '12:00', 'end_time' => '13:00', 'guest_count' => 4], null, $this->desk);

        $this->actingAs($this->desk)->get('/bookings/lookup')->assertOk()->assertSee('Booking code (SMB-…) or phone number');
        $this->actingAs($this->desk)->get('/bookings/lookup?q='.strtolower($b->booking_code))->assertOk()
            ->assertSee('1 booking for')->assertSee('Priya Nair')->assertSee('Confirm booking');
        $res = $this->actingAs($this->desk)->get('/bookings/lookup?q='.urlencode('098200 12345'))->assertOk()->assertSee('2 bookings');
        $this->assertSame([$b->id, $past->id], $res->viewData('results')->pluck('id')->all()); // upcoming before past
        $this->actingAs($this->desk)->get('/bookings/lookup?q=SMB-000000-9999')->assertSee('No booking found');
        $this->actingAs(User::factory()->frontDesk()->create(['branch_id' => Branch::factory()->create()->id]))
            ->get('/bookings/lookup?q='.$b->booking_code)->assertSee('No booking found');

        // List: upcoming by default, all dates on request; status filter.
        $this->actingAs($this->desk)->get('/bookings')->assertSee($b->booking_code)->assertDontSee($past->booking_code);
        $this->actingAs($this->desk)->get('/bookings?from=')->assertSee($past->booking_code);
        $this->actingAs($this->desk)->get('/bookings?from=&status=confirmed')->assertDontSee($b->booking_code);

        // Availability JSON for the booking form.
        $this->actingAs($this->desk)->getJson("/bookings/availability?area_id={$this->room->id}&date={$this->day}")
            ->assertOk()->assertJsonPath('busy.0.code', $b->booking_code)->assertJsonPath('busy.0.time', '16:00–18:00');
        $this->actingAs($this->desk)->getJson("/bookings/availability?area_id={$this->room->id}&date={$this->day}&ignore={$b->id}")->assertJsonCount(0, 'busy');
        $otherArea = Area::create(['outlet_id' => Branch::factory()->create()->id, 'name' => 'X', 'capacity' => 1]);
        $this->actingAs($this->desk)->getJson("/bookings/availability?area_id={$otherArea->id}&date={$this->day}")->assertNotFound();
    }

    public function test_money_format_uses_indian_grouping(): void
    {
        $this->assertSame(['₹0', '₹999', '₹1,000', '₹1,24,999', '₹12,34,567.50', '-₹350'],
            array_map([Format::class, 'money'], [0, 999, 1000, 124999, '1234567.5', -350]));
    }
}
