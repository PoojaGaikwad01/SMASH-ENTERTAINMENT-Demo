<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Jobs\SendBookingNotification;
use App\Mail\BookingNotificationMail;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\NotificationLog;
use App\Models\Package;
use App\Models\User;
use App\Services\BookingNotifier;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmashPhase5NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $outlet;

    private User $desk;

    private Lead $lead;

    private Area $room;

    private Area $arena;

    private Package $package;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-10 10:00'));
        config(['leads.whatsapp.templates' => ['confirmation' => null, 'reminder' => null, 'thank_you' => null],
            'leads.bookings.reminder_hours_before' => 24, 'leads.bookings.thank_you_hours_after' => 3]);

        $this->outlet = Branch::factory()->create(['name' => 'Smash Andheri']);
        $this->desk = User::factory()->frontDesk()->create(['branch_id' => $this->outlet->id]);
        $this->room = Area::create(['outlet_id' => $this->outlet->id, 'name' => 'Party Room A', 'capacity' => 30]);
        $this->arena = Area::create(['outlet_id' => $this->outlet->id, 'name' => 'Event Arena', 'capacity' => 100]);
        $this->package = Package::create(['outlet_id' => $this->outlet->id, 'name' => 'Birthday Basic', 'price' => 14999,
            'duration_minutes' => 120, 'min_guests' => 10, 'max_guests' => 20]);
        $this->lead = Lead::factory()->create(['name' => 'Priya Nair', 'phone' => '9820012345', 'email' => 'priya@example.com', 'branch_id' => $this->outlet->id]);
        $this->service = app(BookingService::class);
    }

    private function booking(string $date = '2026-12-12', string $start = '16:00', ?Area $area = null, array $over = []): Booking
    {
        return $this->service->save($this->lead, $over + ['area_id' => ($area ?? $this->room)->id, 'package_id' => $this->package->id,
            'event_date' => $date, 'start_time' => $start, 'guest_count' => 15, 'advance_paid' => 5000], null, $this->desk);
    }

    /** @return array<string, string> "type.channel" => status */
    private function logs(Booking $b): array
    {
        return $b->notificationLogs()->reorder('id')->get()->mapWithKeys(fn ($l) => ["{$l->type->value}.{$l->channel}" => $l->status])->all();
    }

    // --- Confirmation on confirm ---

    public function test_confirming_queues_whatsapp_and_email_jobs(): void
    {
        Queue::fake();
        $b = $this->booking();

        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'confirmed'])
            ->assertSessionHas('status', fn ($s) => str_ends_with($s, 'Confirmation queued on WhatsApp + email.'));

        $this->assertSame(['confirmation.whatsapp' => 'queued', 'confirmation.email' => 'queued'], $this->logs($b));
        Queue::assertPushed(SendBookingNotification::class, 2);
    }

    public function test_mock_mode_logs_instead_of_sending(): void
    {
        Mail::fake();
        Http::fake();
        $b = $this->booking();
        $this->service->transition($b, BookingStatus::Confirmed);

        $this->assertSame(['confirmation.whatsapp' => 'mocked', 'confirmation.email' => 'mocked'], $this->logs($b));
        Mail::assertNothingSent();
        Http::assertNothingSent();

        $wa = json_decode($b->notificationLogs()->where('channel', 'whatsapp')->value('provider_response'), true);
        $this->assertSame('919820012345', $wa['to']);
        $this->assertSame('(WA_TEMPLATE_CONFIRMATION not set)', $wa['template']);
        $this->assertSame(['Priya Nair', $b->booking_code, 'Sat, 12 Dec 2026', '16:00–18:00', 'Smash Andheri', 'Party Room A · Birthday Basic', '15', '₹9,999'], $wa['params']);
        $this->assertSame("To priya@example.com: Booking confirmed – {$b->booking_code} · Sat 12 Dec, 16:00",
            $b->notificationLogs()->where('channel', 'email')->value('provider_response'));

        $this->assertSame(
            ["Booking confirmation sent via WhatsApp · {$b->booking_code} · mock", "Booking confirmation sent via email · {$b->booking_code} · mock"],
            $this->lead->activities()->where('type', ActivityType::Message)->pluck('body')->all(),
        );
        $this->actingAs($this->desk)->get("/bookings/{$b->id}")->assertOk()
            ->assertSee('Customer messages')->assertSee('Mocked')->assertSee('Resend')->assertSee('Mock mode: logged, not sent.');
    }

    public function test_skips_missing_contact_details_and_cancelled_bookings(): void
    {
        $this->lead->forceFill(['phone' => 'call reception', 'email' => null])->save();
        $b = $this->booking();
        $this->service->transition($b, BookingStatus::Confirmed);
        $this->assertSame(['confirmation.whatsapp' => 'skipped', 'confirmation.email' => 'skipped'], $this->logs($b));
        $this->assertSame(['No valid mobile number on the enquiry.', 'No email address on the enquiry.'],
            $b->notificationLogs()->reorder('id')->pluck('provider_response')->all());

        // Cancelled after queuing → the job skips it.
        Queue::fake();
        $b2 = $this->booking('2026-12-13');
        $log = app(BookingNotifier::class)->notify($b2, NotificationType::Confirmation, ['email'])->sole();
        $this->service->transition($b2, BookingStatus::Cancelled, 'Changed plans');
        (new SendBookingNotification($log->id))->handle(app(BookingNotifier::class));
        $this->assertSame([NotificationLog::SKIPPED, 'Booking was cancelled.'], [$log->fresh()->status, $log->fresh()->provider_response]);
    }

    // --- Live mode ---

    public function test_live_mode_sends_the_template_and_the_email(): void
    {
        config(['leads.mode' => 'live', 'leads.whatsapp.phone_number_id' => '555', 'leads.whatsapp.access_token' => 'tok',
            'leads.whatsapp.templates.confirmation' => 'booking_confirmation', 'leads.whatsapp.template_lang' => 'en']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]])]);
        Mail::fake();

        $b = $this->booking();
        $this->service->transition($b, BookingStatus::Confirmed);

        $this->assertSame(['confirmation.whatsapp' => 'sent', 'confirmation.email' => 'sent'], $this->logs($b));
        $this->assertSame('wamid.ABC', $b->notificationLogs()->where('channel', 'whatsapp')->value('provider_response'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/messages')
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['to'] === '919820012345' && $r['type'] === 'template'
            && $r['template']['name'] === 'booking_confirmation' && $r['template']['language']['code'] === 'en'
            && $r['template']['components'][0]['parameters'][1] === ['type' => 'text', 'text' => $b->booking_code]
            && count($r['template']['components'][0]['parameters']) === 8);
        Mail::assertSent(BookingNotificationMail::class, fn ($m) => $m->hasTo('priya@example.com') && $m->type === NotificationType::Confirmation);

        // No template configured for reminders → WhatsApp skipped (email still goes).
        app(BookingNotifier::class)->notify($b->fresh(), NotificationType::Reminder);
        $this->assertSame('skipped', $this->logs($b)['reminder.whatsapp']);
        $this->assertSame('WA_TEMPLATE_REMINDER is not set.', $b->notificationLogs()->where('type', 'reminder')->where('channel', 'whatsapp')->value('provider_response'));
    }

    public function test_live_api_error_marks_the_log_failed_without_breaking_the_request(): void
    {
        config(['leads.mode' => 'live', 'leads.whatsapp.phone_number_id' => '555', 'leads.whatsapp.access_token' => 'tok',
            'leads.whatsapp.templates.confirmation' => 'booking_confirmation']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist']], 400)]);
        Mail::fake();
        $b = $this->booking();

        $this->actingAs($this->desk)->post("/bookings/{$b->id}/status", ['status' => 'confirmed'])->assertRedirect();
        $this->assertSame(BookingStatus::Confirmed, $b->fresh()->status);
        $log = $b->notificationLogs()->where('channel', 'whatsapp')->sole();
        $this->assertSame(NotificationLog::FAILED, $log->status);
        $this->assertStringContainsString('Template name does not exist', $log->provider_response);
        Http::assertSentCount(1); // 4xx is not retried
    }

    // --- Scheduler: reminder + thank-you, never twice ---

    public function test_scheduler_sends_reminders_and_thank_yous_once(): void
    {
        $at = fn (Booking $b, BookingStatus $s, ?string $confirmed = '2026-12-07 12:00') => tap($b, fn () => $b->forceFill(['status' => $s, 'confirmed_at' => $confirmed])->save());

        $due = $at($this->booking('2026-12-11', '09:00'), BookingStatus::Confirmed);                         // starts in 23 h
        $later = $at($this->booking('2026-12-12', '16:00'), BookingStatus::Confirmed);                       // 54 h away
        $late = $at($this->booking('2026-12-11', '08:00', $this->arena), BookingStatus::Confirmed, '2026-12-10 09:00'); // confirmed inside the window
        $tentative = $this->booking('2026-12-11', '12:00');
        $thanks = $at($this->booking('2026-12-10', '04:00', $this->arena, ['end_time' => '06:00', 'package_id' => null, 'advance_paid' => 0]), BookingStatus::Completed); // ended 4 h ago
        $recent = $at($this->booking('2026-12-10', '07:00', null, ['end_time' => '09:00', 'package_id' => null, 'advance_paid' => 0]), BookingStatus::Completed);         // ended 1 h ago
        $old = $at($this->booking('2026-11-28', '12:00'), BookingStatus::Completed);                         // too old
        $cancelled = $at($this->booking('2026-12-11', '14:00'), BookingStatus::Cancelled);

        $this->artisan('bookings:send-notifications')->expectsOutput('Reminders queued for 1 booking(s); thank-you for 1.')->assertSuccessful();
        $this->assertSame(['reminder.whatsapp' => 'mocked', 'reminder.email' => 'mocked'], $this->logs($due));
        $this->assertSame(['thank_you.whatsapp' => 'mocked', 'thank_you.email' => 'mocked'], $this->logs($thanks));
        foreach ([$later, $late, $tentative, $recent, $old, $cancelled] as $b) {
            $this->assertSame([], $this->logs($b), $b->booking_code);
        }

        // Never twice: re-running sends nothing new, even if a send failed or was skipped.
        $due->notificationLogs()->where('channel', 'email')->update(['status' => NotificationLog::FAILED]);
        $this->artisan('bookings:send-notifications')->expectsOutput('Reminders queued for 0 booking(s); thank-you for 0.');
        $this->assertSame(4, NotificationLog::count());

        // 3 h later the 09:00-ending visit is due for its thank-you.
        $this->travel(3)->hours();
        $this->artisan('bookings:send-notifications')->expectsOutput('Reminders queued for 0 booking(s); thank-you for 1.');
        $this->assertSame(['thank_you.whatsapp' => 'mocked', 'thank_you.email' => 'mocked'], $this->logs($recent));

        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'bookings:send-notifications')));
    }

    // --- Resend ---

    public function test_resend_creates_a_new_attempt_and_respects_status_and_outlet(): void
    {
        $b = $this->booking();
        $this->actingAs($this->desk)->post("/bookings/{$b->id}/notifications", ['type' => 'confirmation'])->assertSessionHasErrors('type'); // still tentative
        $this->service->transition($b, BookingStatus::Confirmed);

        $this->actingAs($this->desk)->post("/bookings/{$b->id}/notifications", ['type' => 'confirmation', 'channel' => 'whatsapp'])
            ->assertSessionHas('status', 'Confirmation queued on WhatsApp.');
        $this->assertSame(2, $b->notificationLogs()->where('channel', 'whatsapp')->where('status', 'mocked')->count());

        $this->actingAs($this->desk)->post("/bookings/{$b->id}/notifications", ['type' => 'reminder'])->assertSessionHas('status', 'Reminder queued on WhatsApp + email.');
        $this->actingAs($this->desk)->post("/bookings/{$b->id}/notifications", ['type' => 'thank_you'])->assertSessionHasErrors('type');
        $this->actingAs($this->desk)->post("/bookings/{$b->id}/notifications", ['type' => 'confirmation', 'channel' => 'sms'])->assertSessionHasErrors('channel');
        $this->assertSame(5, $b->notificationLogs()->count());

        $other = User::factory()->frontDesk()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($other)->post("/bookings/{$b->id}/notifications", ['type' => 'confirmation'])->assertForbidden();
    }

    // --- Email content ---

    public function test_email_content_per_type(): void
    {
        $b = $this->booking()->load(['lead', 'outlet', 'area', 'package', 'addons']);

        $confirmation = (new BookingNotificationMail($b, NotificationType::Confirmation))->render();
        foreach ([$b->booking_code, 'Your booking is confirmed', 'Party Room A', 'Birthday Basic', '₹14,999', '₹9,999', 'Balance due at the counter'] as $text) {
            $this->assertStringContainsString($text, $confirmation);
        }
        $this->assertStringContainsString('arrive 15 minutes early', (new BookingNotificationMail($b, NotificationType::Reminder))->render());
        $thanks = (new BookingNotificationMail($b, NotificationType::ThankYou))->render();
        $this->assertStringContainsString('Thank you for celebrating with us', $thanks);
        $this->assertStringNotContainsString('Balance due', $thanks);
        $this->assertSame('Thank you for visiting Smash Andheri!', (new BookingNotificationMail($b, NotificationType::ThankYou))->subjectLine());
    }
}
