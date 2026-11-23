<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\BookingStatus;
use App\Enums\NotificationType;
use App\Jobs\SendBookingNotification;
use App\Mail\BookingNotificationMail;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Services\Meta\MetaClient;
use App\Support\Format;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Booking messages to the customer: WhatsApp template + email, one notification_logs row per channel.
 * notify() writes the row (status queued) and queues SendBookingNotification; deliver() (in the job) sends and records
 * the outcome. APP_MODE=mock never calls WhatsApp or the mailer: it logs and marks the row "mocked".
 */
class BookingNotifier
{
    /** Thank-you messages are not sent for visits older than this. */
    private const THANK_YOU_MAX_DAYS = 7;

    public function __construct(private MetaClient $meta) {}

    /**
     * Queue $type on $channels (default both). Automatic sends skip channels already attempted for this type
     * (any status — "never twice"); $force = staff Resend, always creates a new attempt.
     *
     * @return Collection<int, NotificationLog>
     */
    public function notify(Booking $booking, NotificationType $type, ?array $channels = null, bool $force = false): Collection
    {
        $attempted = $force ? [] : $booking->notificationLogs()->where('type', $type->value)->pluck('channel')->all();

        return collect($channels ?? NotificationLog::CHANNELS)
            ->diff($attempted)
            ->map(function (string $channel) use ($booking, $type) {
                $log = $booking->notificationLogs()->create([
                    'channel' => $channel, 'type' => $type, 'status' => NotificationLog::QUEUED, 'created_at' => now(),
                ]);
                SendBookingNotification::dispatch($log->id);

                return $log;
            })->values();
    }

    /** Send one queued row and record the result. Never throws. */
    public function deliver(NotificationLog $log): void
    {
        $booking = $log->booking()->with(['lead', 'outlet', 'area', 'package', 'addons', 'paymentLinks'])->first();

        try {
            [$status, $response] = match (true) {
                $booking->status === BookingStatus::Cancelled => [NotificationLog::SKIPPED, 'Booking was cancelled.'],
                $log->type === NotificationType::PaymentLink && ! $booking->openPaymentLink() => [NotificationLog::SKIPPED, 'No open payment link.'],
                $log->channel === 'whatsapp' => $this->whatsapp($booking, $log->type),
                default => $this->email($booking, $log->type),
            };
        } catch (\Throwable $e) {
            Log::warning('Booking notification failed', ['log_id' => $log->id, 'booking' => $booking->booking_code, 'error' => $e->getMessage()]);
            [$status, $response] = [NotificationLog::FAILED, Str::limit($e->getMessage(), 1000)];
        }

        $log->update(['status' => $status, 'provider_response' => $response]);

        if (in_array($status, [NotificationLog::SENT, NotificationLog::MOCKED], true)) {
            // e.g. "Booking confirmation sent via WhatsApp · SMB-261123-0042 · mock" (on the enquiry timeline)
            $booking->lead->logActivity(ActivityType::Message, sprintf('Booking %s sent via %s · %s%s',
                strtolower($log->type->label()), $log->channelLabel(true), $booking->booking_code,
                $status === NotificationLog::MOCKED ? ' · mock' : ''), null);
        }
    }

    /**
     * WhatsApp template body parameters ({{1}}, {{2}}, … in the approved template), per type:
     * confirmation: name, booking code, date, time, outlet, area · package, guests, balance due
     * reminder:     name, booking code, date, time, outlet
     * thank_you:    name, outlet
     * payment_link: name, amount, booking code, payment link, link expiry
     *
     * @return list<string>
     */
    public function templateParams(Booking $b, NotificationType $type): array
    {
        $name = $b->lead->name ?: 'there';
        $date = $b->event_date->format('D, d M Y');

        return match ($type) {
            NotificationType::Confirmation => [$name, $b->booking_code, $date, $b->time_range, $b->outlet->name,
                $b->area->name.' · '.($b->package?->name ?? 'Custom'), (string) $b->guest_count, Format::money($b->balance_due)],
            NotificationType::Reminder => [$name, $b->booking_code, $date, $b->time_range, $b->outlet->name],
            NotificationType::ThankYou => [$name, $b->outlet->name],
            NotificationType::PaymentLink => (fn ($l) => [$name, Format::money($l->amount), $b->booking_code, $l->short_url,
                $l->expires_at?->format('d M, g:i a') ?? '—'])($b->openPaymentLink()),
        };
    }

    /** Confirmed bookings starting within REMINDER_HOURS_BEFORE, confirmed before that window opened, no reminder yet. */
    public function dueReminders(): Collection
    {
        $hours = (int) config('leads.bookings.reminder_hours_before');
        $until = now()->addHours($hours);

        return Booking::where('status', BookingStatus::Confirmed->value)
            ->whereDate('event_date', '>=', today()->toDateString())
            ->whereDate('event_date', '<=', $until->toDateString())
            ->whereDoesntHave('notificationLogs', fn ($q) => $q->where('type', NotificationType::Reminder->value))
            ->get()
            ->filter(fn (Booking $b) => $b->startsAt()->isFuture() && $b->startsAt()->lte($until)
                && (! $b->confirmed_at || $b->confirmed_at->lt($b->startsAt()->subHours($hours))))
            ->values();
    }

    /** Completed bookings that ended THANK_YOU_HOURS_AFTER ago (within the last week), no thank-you yet. */
    public function dueThankYous(): Collection
    {
        $hours = (int) config('leads.bookings.thank_you_hours_after');

        return Booking::where('status', BookingStatus::Completed->value)
            ->whereDate('event_date', '>=', today()->subDays(self::THANK_YOU_MAX_DAYS + 1)->toDateString())
            ->whereDate('event_date', '<=', today()->toDateString())
            ->whereDoesntHave('notificationLogs', fn ($q) => $q->where('type', NotificationType::ThankYou->value))
            ->get()
            ->filter(fn (Booking $b) => $b->endsAt()->addHours($hours)->lte(now()) && $b->endsAt()->gte(now()->subDays(self::THANK_YOU_MAX_DAYS)))
            ->values();
    }

    /** @return array{0: string, 1: string} [status, provider_response] */
    private function whatsapp(Booking $b, NotificationType $type): array
    {
        $phone = Phone::normalize($b->lead->phone);
        if (! $phone) {
            return [NotificationLog::SKIPPED, 'No valid mobile number on the enquiry.'];
        }
        $to = ltrim($phone, '+');
        $params = $this->templateParams($b, $type);

        if ($this->meta->isMock()) {
            Log::info('[mock] WhatsApp booking template not sent', ['booking' => $b->booking_code, 'type' => $type->value]);

            return [NotificationLog::MOCKED, json_encode([
                'to' => $to, 'template' => $type->template() ?? "({$type->envName()} not set)", 'params' => $params,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        if (! $type->template()) {
            return [NotificationLog::SKIPPED, "{$type->envName()} is not set."];
        }

        return [NotificationLog::SENT, $this->meta->sendTemplate($to, $type->template(), config('leads.whatsapp.template_lang'), $params)];
    }

    /** @return array{0: string, 1: string} */
    private function email(Booking $b, NotificationType $type): array
    {
        $email = $b->lead->email;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [NotificationLog::SKIPPED, 'No email address on the enquiry.'];
        }
        $mail = new BookingNotificationMail($b, $type);

        if (config('leads.mode') !== 'live') {
            $mail->render(); // builds the email so template errors still surface in mock mode
            Log::info('[mock] Booking email not sent', ['booking' => $b->booking_code, 'type' => $type->value]);

            return [NotificationLog::MOCKED, "To {$email}: {$mail->subjectLine()}"];
        }

        Mail::to($email)->send($mail);

        return [NotificationLog::SENT, "To {$email}: {$mail->subjectLine()}"];
    }
}
