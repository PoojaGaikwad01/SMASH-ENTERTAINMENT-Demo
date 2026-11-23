{{-- Status buttons for $b (BookingStatus::next()). Cancel needs a reason. Used on the booking page and the counter lookup. --}}
@php
    use App\Enums\BookingStatus;
@endphp
@can('update', $b)
    <div x-data="{ cancel: false }" class="space-y-2">
        <div class="flex flex-wrap gap-2">
            @foreach ($b->status->next() as $to)
                @if ($to === BookingStatus::Cancelled)
                    <button type="button" @click="cancel = !cancel" class="btn btn-secondary text-rose-700">Cancel booking…</button>
                @else
                    <form method="POST" action="{{ route('bookings.status', $b) }}">
                        @csrf
                        <input type="hidden" name="status" value="{{ $to->value }}">
                        <button class="btn {{ $to === BookingStatus::Confirmed ? 'btn-primary' : 'btn-secondary' }}">
                            {{ $to === BookingStatus::Confirmed ? '✓ Confirm booking' : '✓ Mark completed' }}
                        </button>
                    </form>
                @endif
            @endforeach
            @if ($b->status->holdsSlot())
                <a href="{{ route('bookings.edit', $b) }}" class="btn btn-secondary">Edit</a>
            @endif
        </div>
        <form x-show="cancel" x-cloak method="POST" action="{{ route('bookings.status', $b) }}" class="flex flex-wrap gap-2">
            @csrf
            <input type="hidden" name="status" value="cancelled">
            <input name="cancel_reason" required maxlength="255" placeholder="Reason (required)" aria-label="Cancellation reason" class="input min-w-0 flex-1">
            <button class="btn btn-danger">Cancel booking</button>
        </form>
    </div>
@endcan
