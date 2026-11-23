<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Payments\RazorpayClient;
use App\Support\Format;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Booking advances via Razorpay Payment Links (PAYMENTS_ENABLED). createLink() → link sent on WhatsApp + email;
 * markPaid() (webhook or the mock button) credits the booking ONCE per link and confirms a tentative booking.
 */
class PaymentService
{
    public function __construct(
        private RazorpayClient $razorpay,
        private BookingService $bookings,
        private BookingNotifier $notifier,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('leads.payments.enabled');
    }

    /** New link for $amount (₹1 … balance due). Older open links are cancelled. Throws ValidationException / RequestException. */
    public function createLink(Booking $booking, float $amount, ?User $by = null): PaymentLink
    {
        $amount = round($amount, 2);
        $balance = (float) $booking->balance_due;
        $error = match (true) {
            ! $booking->status->holdsSlot() => 'Payment links are only for tentative or confirmed bookings.',
            $balance <= 0 => 'Nothing left to pay on this booking.',
            $amount < 1 || $amount > $balance => 'Amount must be between ₹1 and the balance due ('.Format::money($balance).').',
            default => null,
        };
        throw_if($error, ValidationException::withMessages(['amount' => $error]));

        $lead = $booking->lead;
        $expires = now()->addHours(max(1, (int) config('leads.payments.link_expiry_hours')));
        $entity = $this->razorpay->createPaymentLink([
            'amount' => (int) round($amount * 100), // paise
            'currency' => 'INR',
            'accept_partial' => false,
            'reference_id' => $booking->booking_code.'-'.($booking->paymentLinks()->count() + 1),
            'description' => Str::limit("Advance for {$booking->outlet->name} booking {$booking->booking_code} on {$booking->event_date->format('d M Y')}", 250),
            'expire_by' => $expires->getTimestamp(),
            'customer' => array_filter([
                'name' => $lead->name,
                'contact' => Phone::normalize($lead->phone),
                'email' => filter_var($lead->email, FILTER_VALIDATE_EMAIL) ? $lead->email : null,
            ]),
            'notify' => ['sms' => false, 'email' => false], // we send it ourselves (WhatsApp template + our email)
            'reminder_enable' => false,
            'notes' => ['booking_id' => (string) $booking->id, 'booking_code' => $booking->booking_code],
        ]);

        $previous = $booking->paymentLinks()->where('status', PaymentLink::CREATED)->get();
        $link = DB::transaction(function () use ($booking, $entity, $amount, $expires, $previous, $by) {
            $previous->each->update(['status' => PaymentLink::CANCELLED]);
            $link = $booking->paymentLinks()->create([
                'provider_link_id' => $entity['id'],
                'reference_id' => $entity['reference_id'],
                'short_url' => $entity['short_url'],
                'amount' => $amount,
                'created_by_user_id' => $by?->id ?? auth()->id(),
                'expires_at' => $expires,
            ]);
            $booking->lead->logActivity(ActivityType::Booking,
                "Payment link for ".Format::money($amount)." created · {$booking->booking_code} · expires {$expires->format('d M, g:i a')}", $by);

            return $link;
        });

        $previous->each(fn (PaymentLink $p) => $this->razorpay->cancelPaymentLink($p->provider_link_id));
        $this->notifier->notify($booking->unsetRelation('paymentLinks'), NotificationType::PaymentLink, null, force: true);

        return $link;
    }

    /**
     * Credit a paid link (Razorpay webhook or "Mark as paid (mock)"). Idempotent: the link row is locked and only an unpaid
     * link with an unseen payment id is credited. Returns false when it was already processed.
     */
    public function markPaid(PaymentLink $link, string $paymentId, ?float $amount = null, ?User $by = null): bool
    {
        $booking = DB::transaction(function () use ($link, $paymentId, $amount, $by) {
            $link = PaymentLink::whereKey($link->id)->lockForUpdate()->first();
            if ($link->status === PaymentLink::PAID || PaymentLink::where('provider_payment_id', $paymentId)->exists()) {
                return null;
            }
            $amount = round($amount ?? (float) $link->amount, 2);
            $link->update(['status' => PaymentLink::PAID, 'amount_paid' => $amount, 'provider_payment_id' => $paymentId, 'paid_at' => now()]);

            $booking = Booking::whereKey($link->booking_id)->lockForUpdate()->first();
            $total = (float) $booking->total_amount;
            $advance = round((float) $booking->advance_paid + $amount, 2);
            $booking->forceFill([
                'advance_paid' => $advance,
                'balance_due' => max(0, round($total - $advance, 2)),
                'payment_status' => PaymentStatus::fromAmounts($total, $advance),
            ])->save();
            $booking->lead->logActivity(ActivityType::Booking,
                'Advance '.Format::money($amount)." received online · {$booking->booking_code} · {$paymentId}", $by);

            return $booking;
        });

        if (! $booking) {
            return false;
        }
        $this->bookings->pushExport($booking, 'payment');
        if ($booking->status === BookingStatus::Tentative) {
            $this->bookings->transition($booking, BookingStatus::Confirmed, null, $by); // → enquiry Confirmed + confirmation message
        } elseif ($booking->status === BookingStatus::Cancelled) {
            Log::warning('Payment received for a cancelled booking', ['booking' => $booking->booking_code, 'payment' => $paymentId]);
        }

        return true;
    }

    /** Razorpay webhook body (signature already checked). Returns a short outcome for the response and the log. */
    public function handleWebhook(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');
        if (! in_array($event, ['payment_link.paid', 'payment_link.expired', 'payment_link.cancelled'], true)) {
            return 'ignored';
        }
        $entity = $payload['payload']['payment_link']['entity'] ?? [];
        $link = PaymentLink::where('provider_link_id', (string) ($entity['id'] ?? ''))->first();
        if (! $link) {
            return 'unknown link';
        }

        if ($event === 'payment_link.paid') {
            $paymentId = (string) ($payload['payload']['payment']['entity']['id'] ?? 'plinkpay_'.$link->provider_link_id);
            $paid = isset($entity['amount_paid']) ? $entity['amount_paid'] / 100 : null;

            return $this->markPaid($link, $paymentId, $paid) ? 'paid' : 'already processed';
        }

        PaymentLink::whereKey($link->id)->where('status', PaymentLink::CREATED)
            ->update(['status' => $event === 'payment_link.expired' ? PaymentLink::EXPIRED : PaymentLink::CANCELLED]);

        return Str::after($event, '.');
    }
}
