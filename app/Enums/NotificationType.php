<?php

namespace App\Enums;

/** notification_logs.type — automatic booking messages to the customer (WhatsApp template + email). */
enum NotificationType: string
{
    case Confirmation = 'confirmation';
    case Reminder = 'reminder';
    case ThankYou = 'thank_you';
    case PaymentLink = 'payment_link'; // Phase 6: Razorpay advance link (PAYMENTS_ENABLED)

    public function label(): string
    {
        return match ($this) {
            self::Confirmation => 'Confirmation',
            self::Reminder => 'Reminder',
            self::ThankYou => 'Thank-you',
            self::PaymentLink => 'Payment link',
        };
    }

    /** Approved WhatsApp template name (config leads.whatsapp.templates.*). */
    public function template(): ?string
    {
        return config("leads.whatsapp.templates.{$this->value}") ?: null;
    }

    public function envName(): string
    {
        return 'WA_TEMPLATE_'.strtoupper($this->value);
    }

    /** Which booking states each message makes sense for (manual send / resend). */
    public function allowedFor(BookingStatus $status): bool
    {
        return match ($this) {
            self::Confirmation => in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed], true),
            self::Reminder => $status === BookingStatus::Confirmed,
            self::ThankYou => $status === BookingStatus::Completed,
            self::PaymentLink => config('leads.payments.enabled') && $status->holdsSlot(),
        };
    }
}
