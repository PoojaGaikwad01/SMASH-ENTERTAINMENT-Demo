@php
    use App\Enums\NotificationType;
    use App\Support\Format;
    $b = $booking;
    $name = $b->lead->name ?: 'there';
@endphp
<x-mail::message>
@if ($type === NotificationType::Confirmation)
# Your booking is confirmed 🎉

Hi {{ $name }},

Your booking at **{{ $b->outlet->name }}** is confirmed. Please show the booking code **{{ $b->booking_code }}** at the counter when you arrive.
@elseif ($type === NotificationType::Reminder)
# See you soon!

Hi {{ $name }},

A quick reminder of your booking at **{{ $b->outlet->name }}** on **{{ $b->event_date->format('l, d M') }}** at **{{ substr($b->start_time, 0, 5) }}**. Please arrive 15 minutes early.
@elseif ($type === NotificationType::PaymentLink)
@php
    $link = $b->openPaymentLink();
@endphp
# Complete your booking

Hi {{ $name }},

To confirm your booking at **{{ $b->outlet->name }}**, please pay the advance of **{{ Format::money($link?->amount) }}** securely with Razorpay.@if ($link?->expires_at) The link expires on {{ $link->expires_at->format('d M, g:i a') }}.@endif

@if ($link)
<x-mail::button :url="$link->short_url">Pay {{ Format::money($link->amount) }}</x-mail::button>
@endif
@else
# Thank you for visiting!

Hi {{ $name }},

Thank you for celebrating with us at **{{ $b->outlet->name }}**. We hope everyone had a smashing time, and we'd love to see you again soon!
@endif

@unless ($type === NotificationType::ThankYou)
<x-mail::table>
| Booking | {{ $b->booking_code }} |
|:--|:--|
| Date | {{ $b->event_date->format('l, d M Y') }} |
| Time | {{ $b->time_range }} |
| Where | {{ $b->outlet->name }} · {{ $b->area->name }} |
| Package | {{ $b->package?->name ?? 'Custom booking' }} |
| Guests | {{ $b->guest_count }} |
@if ($b->addons->isNotEmpty())
| Add-ons | {{ $b->addons->map(fn ($a) => $a->name.' × '.$a->pivot->qty)->join(', ') }} |
@endif
| Total | {{ Format::money($b->total_amount) }} |
| Advance paid | {{ Format::money($b->advance_paid) }} |
| **Balance due at the counter** | **{{ Format::money($b->balance_due) }}** |
</x-mail::table>
@endunless

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
