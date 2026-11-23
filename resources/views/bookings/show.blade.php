<x-layouts.app :title="'Booking '.$booking->booking_code">
    <x-slot:actions>
        <a href="{{ route('inbox.show', $booking->lead_id) }}" class="btn btn-secondary">Open enquiry</a>
    </x-slot:actions>

    <div class="grid max-w-5xl gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('bookings._summary', ['b' => $booking])
            @if (config('leads.payments.enabled'))
                @include('bookings._payments', ['b' => $booking])
            @endif
            @include('bookings._notifications', ['b' => $booking])
        </div>
        <div class="space-y-4">
            <div class="card space-y-3 p-4">
                <h2 class="font-semibold">Status</h2>
                @include('bookings._actions', ['b' => $booking])
                @if ($booking->status === \App\Enums\BookingStatus::Tentative)
                    <p class="text-xs text-slate-500">Tentative bookings hold the slot. Confirming also marks the enquiry <strong>Confirmed</strong>.</p>
                @endif
                <dl class="space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    <div>Created {{ $booking->created_at->format('d M Y, g:i a') }}{{ $booking->createdBy ? ' by '.$booking->createdBy->name : '' }}</div>
                    @if ($booking->confirmed_at)<div>Confirmed {{ $booking->confirmed_at->format('d M Y, g:i a') }}</div>@endif
                    @if ($booking->cancel_reason)<div class="text-rose-700">Cancelled: {{ $booking->cancel_reason }}</div>@endif
                </dl>
            </div>
        </div>
    </div>
</x-layouts.app>
