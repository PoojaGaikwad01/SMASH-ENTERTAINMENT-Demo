@php
    use App\Enums\BookingStatus;
    use App\Support\Format;
    $isAdmin = auth()->user()->isAdmin();
@endphp
<x-layouts.app title="Bookings">
    <x-slot:actions>
        @include('bookings._tabs')
    </x-slot:actions>

    <form method="GET" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
        <label class="block lg:col-span-2"><span class="label">Search</span>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Booking code, name or phone" class="input">
        </label>
        <label class="block"><span class="label">Status</span>
            <select name="status" class="input">
                <option value="">All statuses</option>
                @foreach (BookingStatus::cases() as $s)
                    <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="block"><span class="label">Event from</span><input type="date" name="from" value="{{ $from }}" class="input"></label>
        <label class="block"><span class="label">Event to</span><input type="date" name="to" value="{{ request('to') }}" class="input"></label>
        @if ($isAdmin)
            <label class="block"><span class="label">Outlet</span>
                <select name="outlet" class="input">
                    <option value="">All outlets</option>
                    @foreach ($outlets as $o)
                        <option value="{{ $o->id }}" @selected((int) request('outlet') === $o->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="flex gap-2 lg:col-span-6">
            <button class="btn btn-primary">Filter</button>
            <a href="{{ route('bookings.index') }}" class="btn btn-secondary">Upcoming</a>
            <a href="{{ route('bookings.index', ['from' => '']) }}" class="btn btn-secondary">All dates</a>
        </div>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr><th>Booking</th><th>Customer</th><th>When</th><th>Area / package</th><th>Guests</th><th class="text-right">Total · balance</th><th>Status</th></tr>
            </thead>
            <tbody>
                @forelse ($bookings as $b)
                    <tr>
                        <td><a href="{{ route('bookings.show', $b) }}" class="font-mono text-sm font-medium text-orange-700 hover:underline">{{ $b->booking_code }}</a></td>
                        <td>
                            <div class="font-medium">{{ $b->lead?->name ?: '—' }}</div>
                            <div class="text-xs text-slate-500">{{ $b->lead?->phone }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            <div @class(['font-medium', 'text-orange-700' => $b->event_date->isToday()])>{{ $b->event_date->isToday() ? 'Today' : $b->event_date->format('D d M Y') }}</div>
                            <div class="text-xs text-slate-500">{{ $b->time_range }}</div>
                        </td>
                        <td>
                            <div>{{ $b->area?->name }}@if ($isAdmin)<span class="text-xs text-slate-400"> · {{ $b->outlet?->name }}</span>@endif</div>
                            <div class="text-xs text-slate-500">{{ $b->package?->name ?? 'Custom' }}</div>
                        </td>
                        <td>{{ $b->guest_count }}</td>
                        <td class="text-right whitespace-nowrap">
                            <div>{{ Format::money($b->total_amount) }}</div>
                            <div class="text-xs {{ (float) $b->balance_due > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ (float) $b->balance_due > 0 ? Format::money($b->balance_due).' due' : 'Paid' }}</div>
                        </td>
                        <td><span class="badge {{ $b->status->color() }}">{{ $b->status->label() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-8 text-center text-slate-500">No bookings match. New bookings are created from an enquiry (open it → “+ Create booking”).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $bookings->links() }}</div>
</x-layouts.app>
