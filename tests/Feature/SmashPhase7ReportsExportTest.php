<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Package;
use App\Models\User;
use App\Services\BookingExport\BookingExportService;
use App\Services\BookingExport\CsvBookingExport;
use App\Services\BookingExport\NullBookingExport;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmashPhase7ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $outlet;

    private User $desk;

    private Area $room;

    private Area $arena;

    private Package $package;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-10 10:00'));
        $this->outlet = Branch::factory()->create(['name' => 'Smash Andheri', 'slug' => 'andheri']);
        $this->desk = User::factory()->frontDesk()->create(['branch_id' => $this->outlet->id]);
        $this->room = Area::create(['outlet_id' => $this->outlet->id, 'name' => 'Party Room A', 'capacity' => 30]);
        $this->arena = Area::create(['outlet_id' => $this->outlet->id, 'name' => 'Event Arena', 'capacity' => 100]);
        $this->package = Package::create(['outlet_id' => $this->outlet->id, 'name' => 'Birthday Basic', 'price' => 14999, 'duration_minutes' => 120, 'min_guests' => 10, 'max_guests' => 20]);
        $this->service = app(BookingService::class);
    }

    private function book(array $over = [], ?Lead $lead = null): Booking
    {
        $lead ??= Lead::factory()->create(['branch_id' => $over['outlet'] ?? $this->outlet->id, 'name' => 'Priya Nair']);
        unset($over['outlet']);

        return $this->service->save($lead, $over + ['area_id' => $this->room->id, 'package_id' => $this->package->id,
            'event_date' => '2026-12-12', 'start_time' => '16:00', 'guest_count' => 15], null, $this->desk);
    }

    // --- Enquiry report ---

    public function test_enquiry_report_shows_conversion_types_and_lost_reasons(): void
    {
        $mk = fn (array $a) => Lead::factory()->create($a + ['branch_id' => $this->outlet->id, 'source' => LeadSource::PhoneCall, 'created_at' => now()->subDays(2)]);
        $mk(['enquiry_type' => EnquiryType::Birthday, 'status' => LeadStatus::Confirmed]);
        $mk(['enquiry_type' => EnquiryType::Birthday, 'status' => LeadStatus::Completed]);
        $mk(['enquiry_type' => EnquiryType::Birthday, 'status' => LeadStatus::Lost, 'lost_reason' => 'Price too high']);
        $mk(['enquiry_type' => EnquiryType::Corporate, 'status' => LeadStatus::Lost, 'lost_reason' => 'Price too high']);
        $mk(['enquiry_type' => EnquiryType::Corporate, 'status' => LeadStatus::Lost]);
        $mk(['enquiry_type' => EnquiryType::Tickets, 'status' => LeadStatus::New, 'created_at' => now()->subDays(60)]); // outside range

        $res = $this->actingAs($this->desk)->get('/reports')->assertOk()
            ->assertSee('Converted')->assertSee('Lost reasons')->assertSee('Price too high')->assertSee('No reason given')->assertSee('40% confirmed or completed');
        $this->assertSame([5, 2, 3], [$res->viewData('totals')['total'], $res->viewData('totals')['converted'], $res->viewData('totals')['lost']]);
        $this->assertSame([3, 2, 1], [$res->viewData('byType')['Birthday party']['total'], $res->viewData('byType')['Birthday party']['converted'], $res->viewData('byType')['Birthday party']['lost']]);
        $this->assertSame(['Price too high' => 2, 'No reason given' => 1], $res->viewData('lostReasons')->all());

        $csv = $this->actingAs($this->desk)->get('/reports/export')->streamedContent();
        $this->assertStringContainsString('Reminders,"Enquiry type","Preferred date",Guests,"Lost reason","Next follow-up","Entered by",Bookings', $csv);
        $this->assertStringContainsString('Birthday party', $csv);
    }

    // --- Booking report ---

    public function test_booking_report_totals_breakdowns_and_scoping(): void
    {
        $confirmed = $this->book(['advance_paid' => 5000]);                                                  // 14,999 · balance 9,999
        $this->service->transition($confirmed, BookingStatus::Confirmed);
        $tentative = $this->book(['area_id' => $this->arena->id, 'package_id' => null, 'start_time' => '11:00', 'end_time' => '14:00', 'guest_count' => 40]); // custom ₹0, 3 h
        $cancelled = $this->book(['event_date' => '2026-12-14']);
        $this->service->transition($cancelled, BookingStatus::Cancelled, 'Weather');
        $done = $this->book(['event_date' => '2026-12-01', 'advance_paid' => 14999]);
        $this->service->transition($done, BookingStatus::Confirmed);
        $this->service->transition($done->fresh(), BookingStatus::Completed);
        $old = $this->book(['event_date' => '2026-10-01']);                                                  // outside the default range
        $pune = Branch::factory()->create(['name' => 'Smash Pune']);
        $puneArea = Area::create(['outlet_id' => $pune->id, 'name' => 'Pune Room', 'capacity' => 50]);
        $this->book(['outlet' => $pune->id, 'area_id' => $puneArea->id, 'package_id' => null, 'end_time' => '18:00']);

        $res = $this->actingAs($this->desk)->get('/reports?tab=bookings')->assertOk()
            ->assertSee('Booked value')->assertSee('₹29,998')->assertSee('Balance to collect')->assertSee('Weather')->assertSee('Event from');
        $t = $res->viewData('btotals');
        $this->assertSame([3, 29998.0, 19999.0, 9999.0, 2, 1, 1, 70], [$t['count'], $t['value'], $t['advance'], $t['balance_open'], $t['confirmed'], $t['tentative'], $t['cancelled'], $t['guests']]);
        $this->assertSame(['Birthday Basic' => 2, 'Custom (no package)' => 1], $res->viewData('byPackage')->map->count->all());
        $this->assertSame(['Event Arena' => 3.0, 'Party Room A' => 4.0], $res->viewData('byArea')->map->hours->sortKeys()->all());
        $this->assertSame(['Weather' => 1], $res->viewData('cancelReasons')->all());
        $this->assertSame(['2026-12-01', '2026-12-12'], $res->viewData('byEventDay')->keys()->all());
        $this->assertTrue($res->viewData('byOutlet')->isEmpty()); // outlet staff: no per-outlet table

        // Date range is by event date; admin sees every outlet.
        $this->assertSame(1, $this->actingAs($this->desk)->get('/reports?tab=bookings&from=2026-10-01&to=2026-10-31')->viewData('btotals')['count']);
        $admin = $this->actingAs(User::factory()->admin()->create())->get('/reports?tab=bookings')->assertSee('By outlet');
        $this->assertSame(['Smash Andheri' => 3, 'Smash Pune' => 1], $admin->viewData('byOutlet')->map->count->all());
        $this->assertSame(1, $this->actingAs(User::factory()->admin()->create())->get("/reports?tab=bookings&branch_id={$pune->id}")->viewData('btotals')['count']);
        $this->actingAs($this->desk)->get('/reports?tab=nope')->assertSessionHasErrors('tab');
        $this->assertNotNull($old);
    }

    public function test_booking_csv_export_is_scoped_and_formula_safe(): void
    {
        $b = $this->book(['special_requests' => '=HYPERLINK("x")', 'advance_paid' => 5000, 'addons' => []]);
        $this->book(['outlet' => Branch::factory()->create()->id, 'area_id' => Area::create(['outlet_id' => Branch::latest('id')->value('id'), 'name' => 'X', 'capacity' => 0])->id, 'package_id' => null, 'end_time' => '17:00']);

        $res = $this->actingAs($this->desk)->get('/reports/export?tab=bookings')->assertOk();
        $this->assertStringContainsString('bookings-20261111-20270109.csv', $res->headers->get('Content-Disposition')); // default: last 30 + next 30 days
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Booking code\",Status,Payment,Outlet,Area,Package,\"Event date\"", $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1); // header + 1 (other outlet excluded)
        $this->assertStringContainsString("{$b->booking_code},Tentative,\"Advance paid\",\"Smash Andheri\",\"Party Room A\",\"Birthday Basic\",2026-12-12,16:00,18:00,15", $csv);
        $this->assertStringContainsString('14999.00,0.00,14999.00,5000.00,9999.00,"\'=HYPERLINK(""x"")"', $csv);
    }

    // --- BookingExportService ---

    public function test_export_service_defaults_to_no_op(): void
    {
        Storage::fake('local');
        $this->assertInstanceOf(NullBookingExport::class, app(BookingExportService::class));
        $b = $this->book();
        $this->service->transition($b, BookingStatus::Confirmed);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->artisan('bookings:export', ['--date' => '2026-12-12'])
            ->expectsOutput('1 booking(s) found; BOOKING_EXPORT_DRIVER=none exports nothing.')->assertSuccessful();
    }

    public function test_csv_driver_logs_every_change_and_exports_the_day(): void
    {
        Storage::fake('local');
        config(['leads.booking_export.driver' => 'csv', 'leads.booking_export.path' => 'pos']);
        $this->service = app(BookingService::class);
        $this->assertInstanceOf(CsvBookingExport::class, app(BookingExportService::class));

        $b = $this->book();
        $this->service->transition($b, BookingStatus::Confirmed);
        $other = $this->book(['area_id' => $this->arena->id]);
        $this->service->transition($other, BookingStatus::Cancelled, 'Weather');

        $events = array_map('str_getcsv', array_slice(explode("\n", trim(Storage::disk('local')->get('pos/booking-events-2026-12-10.csv'))), 1));
        $this->assertSame([['created', $b->booking_code], ['confirmed', $b->booking_code], ['created', $other->booking_code], ['cancelled', $other->booking_code]],
            array_map(fn ($r) => [$r[0], $r[2]], $events));
        $this->assertSame('Confirmed', $events[1][3]);

        $this->artisan('bookings:export', ['--date' => '2026-12-12', '--outlet' => 'andheri'])->assertSuccessful()
            ->expectsOutputToContain('Exported 1 booking(s) to');
        $day = Storage::disk('local')->get('pos/bookings-2026-12-12-andheri.csv');
        $this->assertStringContainsString($b->booking_code, $day);
        $this->assertStringNotContainsString($other->booking_code, $day); // cancelled bookings aren't in the day list

        $this->artisan('bookings:export', ['--date' => 'nonsense'])->assertExitCode(2);
        $this->artisan('bookings:export', ['--outlet' => 'nope'])->assertExitCode(2);
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'bookings:export')));
    }

    public function test_a_failing_export_never_blocks_bookings(): void
    {
        $this->app->instance(BookingExportService::class, new class implements BookingExportService
        {
            public function push(Booking $booking, string $event): void
            {
                throw new \RuntimeException('POS down');
            }

            public function exportMany(iterable $bookings, string $name): ?string
            {
                return null;
            }
        });
        $this->service = app(BookingService::class);

        $b = $this->book();
        $this->service->transition($b, BookingStatus::Confirmed);
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
    }
}
