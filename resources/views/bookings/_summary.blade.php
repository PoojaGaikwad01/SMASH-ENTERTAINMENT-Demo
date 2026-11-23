{{-- Booking details card (show page + counter lookup). Expects $b with lead, area, package, addons, outlet loaded. --}}
@php
    use App\Support\Format;
@endphp
<div class="card divide-y divide-slate-100">
    <div class="flex flex-wrap items-center gap-2 p-4">
        <span class="font-mono text-lg font-semibold">{{ $b->booking_code }}</span>
        <span class="badge {{ $b->status->color() }}">{{ $b->status->label() }}</span>
        <span class="badge {{ $b->payment_status->color() }}">{{ $b->payment_status->label() }}</span>
        @if ($b->event_date->isToday())<span class="badge bg-orange-100 text-orange-800">Today</span>@endif
    </div>

    <dl class="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs text-slate-500">Customer</dt>
            <dd class="font-medium">{{ $b->lead?->name ?: '—' }}</dd>
            <dd class="text-slate-600">
                @if ($b->lead?->phone)<a href="tel:{{ $b->lead->phone }}" class="hover:underline">{{ $b->lead->phone }}</a>@endif
                @if ($b->lead?->email) · {{ $b->lead->email }}@endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">When</dt>
            <dd class="font-medium">{{ $b->event_date->format('D, d M Y') }} · {{ $b->time_range }}</dd>
            <dd class="text-slate-600">{{ $b->area?->name }} · {{ $b->outlet?->name }}</dd>
        </div>
        <div>
            <dt class="text-xs text-slate-500">Guests</dt>
            <dd>{{ $b->guest_count }}@if ($b->child_name) · Birthday: <span class="font-medium">{{ $b->child_name }}</span>{{ $b->child_age !== null ? ' (turning '.$b->child_age.')' : '' }}@endif</dd>
        </div>
        @if ($b->special_requests)
            <div class="sm:col-span-2">
                <dt class="text-xs text-slate-500">Special requests</dt>
                <dd class="whitespace-pre-line">{{ $b->special_requests }}</dd>
            </div>
        @endif
    </dl>

    <table class="w-full text-sm">
        <tbody class="divide-y divide-slate-100">
            <tr>
                <td class="px-4 py-2">{{ $b->package?->name ?? 'No package (custom booking)' }}</td>
                <td class="px-4 py-2 text-right whitespace-nowrap">{{ Format::money($b->package_price) }}</td>
            </tr>
            @foreach ($b->addons as $a)
                <tr>
                    <td class="px-4 py-2 text-slate-600">+ {{ $a->name }} × {{ $a->pivot->qty }} <span class="text-xs text-slate-400">@ {{ Format::money($a->pivot->price) }}</span></td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">{{ Format::money($a->pivot->qty * $a->pivot->price) }}</td>
                </tr>
            @endforeach
            <tr class="font-semibold">
                <td class="px-4 py-2">Total</td>
                <td class="px-4 py-2 text-right">{{ Format::money($b->total_amount) }}</td>
            </tr>
            <tr>
                <td class="px-4 py-2 text-slate-600">Advance received</td>
                <td class="px-4 py-2 text-right">{{ Format::money($b->advance_paid) }}</td>
            </tr>
            <tr @class(['font-semibold', 'text-rose-700' => (float) $b->balance_due > 0, 'text-emerald-700' => (float) $b->balance_due <= 0])>
                <td class="px-4 py-2">Balance due at the counter</td>
                <td class="px-4 py-2 text-right">{{ Format::money($b->balance_due) }}</td>
            </tr>
        </tbody>
    </table>
</div>
