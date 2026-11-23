<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Mail\BookingNotificationMail;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\PaymentLink;
use App\Models\Package;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SmashPhase6PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private Lead $lead;

    private Area $room;

    private Package $package;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-10 10:00'));
        config([
            'leads.payments.enabled' => true, 'leads.payments.advance_percent' => 50, 'leads.payments.link_expiry_hours' => 48,
            'leads.payments.razorpay' => ['key_id' => 'rzp_test_key', 'key_secret' => 'secret', 'webhook_secret' => 'whsec'],
            'leads.whatsapp.templates' => ['confirmation' => null, 'reminder' => null, 'thank_you' => null, 'payment_link' => null],
        ]);

        $outlet = Branch::factory()->create(['name' => 'Smash Andheri']);
        $this->desk = User::factory()->frontDesk()->create(['branch_id' => $outlet->id]);
        $this->room = Area::create(['outlet_id' => $outlet->id, 'name' => 'Party Room A', 'capacity' => 30]);
        $this->package = Package::create(['outlet_id' => $outlet->id, 'name' => 'Birthday Basic', 'price' => 14999, 'duration_minutes' => 120, 'min_guests' => 10, 'max_guests' => 20]);
        $this->lead = Lead::factory()->create(['name' => 'Priya Nair', 'phone' => '9820012345', 'email' => 'priya@example.com', 'branch_id' => $outlet->id]);
        $this->booking = app(BookingService::class)->save($this->lead, ['area_id' => $this->room->id, 'package_id' => $this->package->id,
            'event_date' => '2026-12-12', 'start_time' => '16:00', 'guest_count' => 15], null, $this->desk);
    }

    private function createLink(float $amount = 7500): TestResponse
    {
        return $this->actingAs($this->desk)->post("/bookings/{$this->booking->id}/payment-links", ['amount' => $amount]);
    }

    private function webhook(array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'whsec'),
        ], $body);
    }

    private function paidEvent(PaymentLink $link, string $paymentId = 'pay_ABC'): array
    {
        $paise = (int) round($link->amount * 100);

        return ['entity' => 'event', 'event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => $link->provider_link_id, 'amount' => $paise, 'amount_paid' => $paise, 'status' => 'paid', 'reference_id' => $link->reference_id]],
            'payment' => ['entity' => ['id' => $paymentId, 'amount' => $paise, 'status' => 'captured']],
        ]];
    }

    public function test_everything_is_off_without_payments_enabled(): void
    {
        config(['leads.payments.enabled' => false]);
        $link = $this->booking->paymentLinks()->create(['provider_link_id' => 'plink_x', 'reference_id' => 'r', 'short_url' => 'https://rzp.io/i/x', 'amount' => 100]);

        $this->actingAs($this->desk)->get("/bookings/{$this->booking->id}")->assertOk()->assertDontSee('Advance payment');
        $this->createLink()->assertNotFound();
        $this->actingAs($this->desk)->post("/payment-links/{$link->id}/mock-paid")->assertNotFound();
        $this->webhook($this->paidEvent($link))->assertNotFound();
        $this->assertFalse(NotificationType::PaymentLink->allowedFor(BookingStatus::Tentative));
    }

    public function test_mock_link_is_created_sent_and_replaces_the_open_one(): void
    {
        $this->actingAs($this->desk)->get("/bookings/{$this->booking->id}")->assertOk()
            ->assertSee('Advance payment')->assertSee('value="7500"', false)->assertSee('Mock mode: no real link');

        $this->createLink(20000)->assertSessionHasErrors('amount'); // more than the balance
        $this->createLink(0)->assertSessionHasErrors('amount');
        $this->createLink(7500)->assertSessionHas('status', 'Payment link for ₹7,500 created and sent on WhatsApp + email.');

        $link = PaymentLink::sole();
        $code = $this->booking->booking_code;
        $this->assertStringStartsWith('plink_mock_', $link->provider_link_id);
        $this->assertSame(["{$code}-1", '7500.00', PaymentLink::CREATED, '2026-12-12 10:00'],
            [$link->reference_id, $link->amount, $link->status, $link->expires_at->format('Y-m-d H:i')]);

        $logs = $this->booking->notificationLogs()->where('type', 'payment_link')->reorder('id')->get();
        $this->assertSame(['whatsapp' => 'mocked', 'email' => 'mocked'], $logs->pluck('status', 'channel')->all());
        $this->assertSame(['Priya Nair', '₹7,500', $code, $link->short_url, '12 Dec, 10:00 am'], json_decode($logs[0]->provider_response, true)['params']);
        $this->assertSame("To priya@example.com: Pay your advance of ₹7,500 – {$code}", $logs[1]->provider_response);
        $mail = (new BookingNotificationMail($this->booking->fresh()->load(['lead', 'outlet', 'area', 'package', 'addons']), NotificationType::PaymentLink))->render();
        $this->assertStringContainsString($link->short_url, $mail);
        $this->assertStringContainsString('Pay ₹7,500', $mail);
        $this->assertTrue($this->lead->activities()->whereLike('body', 'Payment link for ₹7,500 created · '.$code.'%')->exists());

        // A new link cancels the open one.
        $this->createLink(5000)->assertSessionHasNoErrors();
        $this->assertSame([PaymentLink::CANCELLED, PaymentLink::CREATED], PaymentLink::orderBy('id')->pluck('status')->all());
        $this->assertSame("{$code}-2", PaymentLink::latest('id')->value('reference_id'));
    }

    public function test_live_mode_creates_a_razorpay_payment_link(): void
    {
        config(['leads.mode' => 'live']);
        Mail::fake();
        Http::fake(['api.razorpay.com/*' => Http::sequence()
            ->push(['id' => 'plink_Live1', 'short_url' => 'https://rzp.io/i/Live1', 'reference_id' => $this->booking->booking_code.'-1', 'status' => 'created'])
            ->push(['error' => ['description' => 'Authentication failed']], 401)]);

        $this->createLink(7500)->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.razorpay.com/v1/payment_links'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_key:secret'))
            && $r['amount'] === 750000 && $r['currency'] === 'INR' && $r['accept_partial'] === false
            && $r['reference_id'] === $this->booking->booking_code.'-1'
            && $r['customer'] === ['name' => 'Priya Nair', 'contact' => '+919820012345', 'email' => 'priya@example.com']
            && $r['notify'] === ['sms' => false, 'email' => false]
            && $r['expire_by'] === Carbon::parse('2026-12-12 10:00')->getTimestamp()
            && $r['notes']['booking_code'] === $this->booking->booking_code);
        $this->assertSame(['plink_Live1', 'https://rzp.io/i/Live1'], [PaymentLink::sole()->provider_link_id, PaymentLink::sole()->short_url]);
        Mail::assertSent(BookingNotificationMail::class, fn ($m) => $m->type === NotificationType::PaymentLink);

        $this->createLink(1000)->assertSessionHasErrors(['amount' => 'Razorpay could not create the link: Authentication failed']);
    }

    public function test_signed_webhook_credits_the_advance_once_and_confirms_the_booking(): void
    {
        $this->createLink(7500);
        $link = PaymentLink::sole();

        $this->webhook($this->paidEvent($link), 'bad-signature')->assertForbidden();
        $this->assertSame('0.00', $this->booking->fresh()->advance_paid);

        $this->webhook($this->paidEvent($link))->assertOk()->assertJson(['outcome' => 'paid']);
        $b = $this->booking->fresh();
        $this->assertSame(['7500.00', '7499.00', PaymentStatus::Partial, BookingStatus::Confirmed], [$b->advance_paid, $b->balance_due, $b->payment_status, $b->status]);
        $this->assertSame([PaymentLink::PAID, 'pay_ABC', '7500.00'], [$link->fresh()->status, $link->fresh()->provider_payment_id, $link->fresh()->amount_paid]);
        $this->assertSame(LeadStatus::Confirmed, $this->lead->fresh()->status);
        $this->assertSame(2, $b->notificationLogs()->where('type', 'confirmation')->count()); // confirmation went out
        $this->assertTrue($this->lead->activities()->whereLike('body', 'Advance ₹7,500 received online · %pay_ABC')->exists());

        // Replays (Razorpay retries) don't credit twice.
        $this->webhook($this->paidEvent($link))->assertOk()->assertJson(['outcome' => 'already processed']);
        $this->assertSame('7500.00', $this->booking->fresh()->advance_paid);

        $this->webhook(['event' => 'payment.captured', 'payload' => []])->assertOk()->assertJson(['outcome' => 'ignored']);
        $this->webhook(['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_nope']]]])->assertJson(['outcome' => 'unknown link']);

        $this->createLink(1000);
        $open = PaymentLink::latest('id')->first();
        $this->webhook(['event' => 'payment_link.expired', 'payload' => ['payment_link' => ['entity' => ['id' => $open->provider_link_id]]]])->assertJson(['outcome' => 'expired']);
        $this->assertSame(PaymentLink::EXPIRED, $open->fresh()->status);
    }

    public function test_mock_mark_as_paid_button(): void
    {
        $this->createLink(7500);
        $link = PaymentLink::sole();
        $this->actingAs($this->desk)->get("/bookings/{$this->booking->id}")->assertSee('Mark as paid (mock)');

        $other = User::factory()->frontDesk()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($other)->post("/payment-links/{$link->id}/mock-paid")->assertForbidden();

        $this->actingAs($this->desk)->post("/payment-links/{$link->id}/mock-paid")->assertSessionHas('status', 'Marked as paid (mock): ₹7,500 added to the advance.');
        $this->assertSame(BookingStatus::Confirmed, $this->booking->fresh()->status);
        $this->actingAs($this->desk)->post("/payment-links/{$link->id}/mock-paid")->assertSessionHas('status', 'This link was already paid.');

        // Pay the rest → fully paid; no more links offered. Live mode has no mock button.
        $this->booking->refresh();
        $this->assertSame(7499.0, $this->booking->suggestedAdvance());
        app(PaymentService::class)->markPaid(app(PaymentService::class)->createLink($this->booking, 7499), 'pay_REST');
        $b = $this->booking->fresh();
        $this->assertSame(['14999.00', '0.00', PaymentStatus::Paid], [$b->advance_paid, $b->balance_due, $b->payment_status]);
        $this->actingAs($this->desk)->get("/bookings/{$b->id}")->assertDontSee('Create link')->assertSee('Fully paid');

        config(['leads.mode' => 'live']);
        $this->actingAs($this->desk)->post("/payment-links/{$link->id}/mock-paid")->assertNotFound();
    }

    public function test_payment_link_message_and_edit_form_guard(): void
    {
        $this->createLink(7500);
        $this->actingAs($this->desk)->get("/bookings/{$this->booking->id}/edit")->assertSee('name="advance_paid_was" value="0.00"', false);
        $edit = ['area_id' => $this->room->id, 'package_id' => $this->package->id, 'event_date' => '2026-12-12', 'start_time' => '16:00', 'guest_count' => 16];

        app(PaymentService::class)->markPaid(PaymentLink::sole(), 'pay_X'); // lands while the form is open
        $this->actingAs($this->desk)->put("/bookings/{$this->booking->id}", $edit + ['advance_paid' => 0, 'advance_paid_was' => 0])
            ->assertSessionHasErrors(['advance_paid' => 'A payment was recorded while you were editing (advance is now ₹7,500). Reload the page and try again.']);
        $this->actingAs($this->desk)->put("/bookings/{$this->booking->id}", $edit + ['advance_paid' => 7500, 'advance_paid_was' => 7500])->assertSessionHasNoErrors();
        $this->assertSame(['7500.00', 16], [$this->booking->fresh()->advance_paid, $this->booking->fresh()->guest_count]);

        // Resending the link message with no open link → skipped.
        $this->actingAs($this->desk)->post("/bookings/{$this->booking->id}/notifications", ['type' => 'payment_link', 'channel' => 'email']);
        $this->assertSame(['skipped', 'No open payment link.'], [
            $this->booking->notificationLogs()->latest('id')->value('status'), $this->booking->notificationLogs()->latest('id')->value('provider_response'),
        ]);
    }
}
