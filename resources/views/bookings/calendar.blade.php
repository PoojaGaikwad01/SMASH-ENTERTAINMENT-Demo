{{-- Availability calendar per outlet. Day = time grid per area; week = areas × days. Cancelled bookings are hidden. --}}
@php
    use App\Enums\BookingStatus;
    use Carbon\CarbonPeriod;
    $isAdmin = auth()->user()->isAdmin();
    $hourPx = 56;
    [$h0, $h1] = $hours;
    $link = fn (array $over = []) => route('bookings.calendar', array_merge(
        ['outlet' => $isAdmin ? $outlet->id : null, 'view' => $view, 'date' => $date->toDateString()], $over));
    $step = $view === 'week' ? 7 : 1;
    $mins = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
    $tone = fn ($b) => match ($b->status) {
        BookingStatus::Tentative => 'border-amber-400 bg-amber-50 text-amber-900',
        BookingStatus::Confirmed => 'border-emerald-500 bg-emerald-50 text-emerald-900',
        default => 'border-slate-300 bg-slate-100 text-slate-500',
    };
    $cells = $bookings->groupBy(fn ($b) => $b->area_id.'|'.$b->event_date->toDateString());
@endphp
<x-layouts.app title="Calendar">
    <x-slot:actions>
        @include('bookings._tabs')
    </x-slot:actions>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @if ($isAdmin)
            <form method="GET" class="contents">
                <input type="hidden" name="view" value="{{ $view }}">
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <select name="outlet" onchange="this.form.submit()" aria-label="Outlet" class="input w-auto py-1.5">
                    @foreach ($outlets as $o)
                        <option value="{{ $o->id }}" @selected($o->id === $outlet->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
            </form>
        @else
            <span class="font-medium">{{ $outlet->name }}</span>
        @endif

        <div class="flex rounded-lg border border-slate-300 bg-white p-0.5 text-sm">
            @foreach (['day' => 'Day', 'week' => 'Week'] as $v => $label)
                <a href="{{ $link(['view' => $v]) }}" @class(['rounded-md px-3 py-1', 'bg-orange-600 text-white' => $view === $v, 'text-slate-600' => $view !== $v])>{{ $label }}</a>
            @endforeach
        </div>
        <a href="{{ $link(['date' => $date->copy()->subDays($step)->toDateString()]) }}" class="btn btn-secondary px-2.5 py-1.5" aria-label="Previous">‹</a>
        <a href="{{ $link(['date' => today()->toDateString()]) }}" class="btn btn-secondary py-1.5">Today</a>
        <a href="{{ $link(['date' => $date->copy()->addDays($step)->toDateString()]) }}" class="btn btn-secondary px-2.5 py-1.5" aria-label="Next">›</a>
        <form method="GET" class="contents">
            @if ($isAdmin)<input type="hidden" name="outlet" value="{{ $outlet->id }}">@endif
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" aria-label="Go to date" class="input w-auto py-1.5">
        </form>
        <h2 class="font-semibold">
            {{ $view === 'week' ? $from->format('d M').' – '.$to->format('d M Y') : $date->format('l, d M Y') }}
        </h2>
        <div class="ml-auto flex gap-2 text-xs">
            <span class="badge {{ BookingStatus::Tentative->color() }}">Tentative</span>
            <span class="badge {{ BookingStatus::Confirmed->color() }}">Confirmed</span>
            <span class="badge {{ BookingStatus::Completed->color() }}">Completed</span>
        </div>
    </div>

    @if ($areas->isEmpty())
        <div class="card p-8 text-center text-slate-500">
            No areas set up for {{ $outlet->name }} yet.
            @if (auth()->user()->canManageCatalog())<a href="{{ route('catalog.index', ['type' => 'areas']) }}" class="text-orange-700 hover:underline">Add areas</a>@endif
        </div>
    @elseif ($view === 'day')
        <div class="card overflow-x-auto">
            <div class="grid min-w-max" style="grid-template-columns: 3.5rem repeat({{ $areas->count() }}, minmax(11rem, 1fr))">
                <div class="border-b border-slate-200"></div>
                @foreach ($areas as $a)
                    <div class="border-b border-l border-slate-200 px-2 py-2 text-sm font-medium">
                        {{ $a->name }} <span class="text-xs font-normal text-slate-400">{{ $a->capacity ? '· '.$a->capacity : '' }}</span>
                    </div>
                @endforeach

                <div>
                    @for ($h = $h0; $h < $h1; $h++)
                        <div class="pr-2 text-right text-xs text-slate-400" style="height: {{ $hourPx }}px">{{ sprintf('%02d:00', $h) }}</div>
                    @endfor
                </div>
                @foreach ($areas as $a)
                    <div class="relative border-l border-slate-200"
                         style="height: {{ ($h1 - $h0) * $hourPx }}px; background-image: repeating-linear-gradient(to bottom, #e2e8f0 0, #e2e8f0 1px, transparent 1px, transparent {{ $hourPx }}px)">
                        @foreach ($cells->get($a->id.'|'.$date->toDateString(), []) as $b)
                            @php
                                $top = ($mins($b->start_time) - $h0 * 60) / 60 * $hourPx;
                                $height = max(22, ($mins($b->end_time) - $mins($b->start_time)) / 60 * $hourPx - 2);
                            @endphp
                            <a href="{{ route('bookings.show', $b) }}" title="{{ $b->booking_code }} · {{ $b->status->label() }}"
                               class="absolute inset-x-1 overflow-hidden rounded-md border-l-4 px-2 py-1 text-xs shadow-sm hover:brightness-95 {{ $tone($b) }}"
                               style="top: {{ $top }}px; height: {{ $height }}px">
                                <div class="font-semibold">{{ $b->time_range }} · {{ $b->lead?->name ?: $b->booking_code }}</div>
                                <div class="truncate">{{ $b->guest_count }} guests · {{ $b->package?->name ?? 'Custom' }}</div>
                                <div class="truncate opacity-75">{{ $b->booking_code }} · {{ $b->status->label() }}</div>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table min-w-max">
                <thead>
                    <tr>
                        <th>Area</th>
                        @foreach (CarbonPeriod::create($from, $to) as $d)
                            <th @class(['text-orange-700' => $d->isToday()])>
                                <a href="{{ $link(['view' => 'day', 'date' => $d->toDateString()]) }}" class="hover:underline">{{ $d->format('D d') }}</a>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($areas as $a)
                        <tr>
                            <td class="font-medium whitespace-nowrap">{{ $a->name }}</td>
                            @foreach (CarbonPeriod::create($from, $to) as $d)
                                <td class="min-w-32 align-top">
                                    <div class="space-y-1">
                                        @forelse ($cells->get($a->id.'|'.$d->toDateString(), []) as $b)
                                            <a href="{{ route('bookings.show', $b) }}" class="block rounded border-l-4 px-1.5 py-0.5 text-xs {{ $tone($b) }}" title="{{ $b->booking_code }} · {{ $b->status->label() }}">
                                                <span class="font-semibold">{{ $b->time_range }}</span>
                                                <span class="block truncate">{{ $b->lead?->name ?: $b->booking_code }} · {{ $b->guest_count }}</span>
                                            </a>
                                        @empty
                                            <span class="text-xs text-emerald-600">Free</span>
                                        @endforelse
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
