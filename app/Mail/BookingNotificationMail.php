<?php

namespace App\Mail;

use App\Enums\NotificationType;
use App\Models\Booking;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Booking confirmation / reminder / thank-you email (markdown view mail.booking). Sent from the queued SendBookingNotification job. */
class BookingNotificationMail extends Mailable
{
    public function __construct(public Booking $booking, public NotificationType $type) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.booking');
    }

    public function subjectLine(): string
    {
        $b = $this->booking;
        $when = $b->event_date->format('D d M').', '.substr($b->start_time, 0, 5);

        return match ($this->type) {
            NotificationType::Confirmation => "Booking confirmed – {$b->booking_code} · {$when}",
            NotificationType::Reminder => "Reminder: your {$b->outlet->name} booking on {$when}",
            NotificationType::ThankYou => "Thank you for visiting {$b->outlet->name}!",
            NotificationType::PaymentLink => 'Pay your advance of '.\App\Support\Format::money($b->openPaymentLink()?->amount)." – {$b->booking_code}",
        };
    }
}
