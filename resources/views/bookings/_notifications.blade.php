{{-- Customer messages (notification_logs) for booking $b + Send / Resend (BookingController@notify, always a new attempt). --}}
@php
    use App\Enums\NotificationType;
    $sendable = collect(NotificationType::cases())->filter(fn ($t) => $t !== NotificationType::PaymentLink && $t->allowedFor($b->status)); // links are sent from the payment card
    $canUpdate = auth()->user()->can('update', $b);
@endphp
<div class="card">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 p-4">
        <div>
            <h2 class="font-semibold">Customer messages</h2>
            <p class="text-xs text-slate-500">
                WhatsApp template + email. Confirmation on confirm, reminder {{ config('leads.bookings.reminder_hours_before') }} h before,
                thank-you {{ config('leads.bookings.thank_you_hours_after') }} h after a completed visit.
                @if (config('leads.mode') !== 'live')<span class="font-medium text-sky-700">Mock mode: logged, not sent.</span>@endif
            </p>
        </div>
        @if ($canUpdate)
            <div class="flex flex-wrap gap-2">
                @foreach ($sendable as $t)
                    <form method="POST" action="{{ route('bookings.notify', $b) }}">
                        @csrf
                        <input type="hidden" name="type" value="{{ $t->value }}">
                        <button class="btn btn-secondary py-1.5 text-xs">Send {{ strtolower($t->label()) }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="table">
            <thead><tr><th>Message</th><th>Status</th><th>Details</th><th>When</th><th></th></tr></thead>
            <tbody>
                @forelse ($b->notificationLogs as $log)
                    <tr>
                        <td class="whitespace-nowrap">{{ $log->type->label() }} · {{ $log->channelLabel() }}</td>
                        <td><span class="badge {{ $log->statusColor() }}">{{ ucfirst($log->status) }}</span></td>
                        <td class="max-w-xs truncate text-xs text-slate-500" title="{{ $log->provider_response }}">{{ $log->provider_response ?? '—' }}</td>
                        <td class="text-xs whitespace-nowrap text-slate-500">{{ $log->created_at->format('d M, g:i a') }}</td>
                        <td class="text-right">
                            @if ($canUpdate && $log->type->allowedFor($b->status))
                                <form method="POST" action="{{ route('bookings.notify', $b) }}">
                                    @csrf
                                    <input type="hidden" name="type" value="{{ $log->type->value }}">
                                    <input type="hidden" name="channel" value="{{ $log->channel }}">
                                    <button class="text-xs font-medium text-orange-700 hover:underline">Resend</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-sm text-slate-500">No messages yet. The confirmation goes out when the booking is confirmed.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
