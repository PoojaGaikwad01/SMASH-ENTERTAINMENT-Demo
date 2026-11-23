{{-- Bookings tab (ReportController::bookingReport), by event date. Value/advance exclude cancelled bookings. --}}
@php
    use App\Support\Format;
    $isAdmin = auth()->user()->isAdmin();
    $t = $btotals;
    $cards = [
        ['Bookings', number_format($t['count']), $t['confirmed'].' confirmed/completed · '.$t['tentative'].' tentative'],
        ['Booked value', Format::money($t['value']), $t['avg_value'] !== null ? 'Avg '.Format::money(round($t['avg_value'])).' per booking' : null],
        ['Advance collected', Format::money($t['advance']), $t['value'] ? round($t['advance'] / $t['value'] * 100).'% of booked value' : null],
        ['Balance to collect', Format::money($t['balance_open']), 'Tentative + confirmed, at the counter'],
        ['Cancelled', number_format($t['cancelled']), number_format($t['guests']).' guests booked (excl. cancelled)'],
    ];
    $maxDay = $byEventDay->max('value') ?: 0;
    $moneyTable = fn (string $heading, string $col, $rows) => compact('heading', 'col', 'rows');
@endphp
<div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
    @foreach ($cards as [$label, $value, $hint])
        <div class="card p-4">
            <div class="text-xs font-medium text-slate-500 uppercase">{{ $label }}</div>
            <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
            @if ($hint)<div class="mt-0.5 text-xs text-slate-400">{{ $hint }}</div>@endif
        </div>
    @endforeach
</div>

<div class="grid gap-6 xl:grid-cols-2">
    @foreach (array_filter([
        $isAdmin ? $moneyTable('By outlet', 'Outlet', $byOutlet) : null,
        $moneyTable('By package', 'Package', $byPackage),
        $moneyTable('By status', 'Status', $byBookingStatus),
    ]) as $table)
        <section class="card overflow-x-auto">
            <h2 class="px-4 pt-4 pb-2 font-semibold">{{ $table['heading'] }}</h2>
            <table class="table">
                <thead><tr><th>{{ $table['col'] }}</th><th class="text-right">Bookings</th><th class="text-right">Guests</th><th class="text-right">Value</th><th class="text-right">Advance</th></tr></thead>
                <tbody>
                    @forelse ($table['rows'] as $name => $r)
                        <tr @class(['text-slate-400' => ! $r['count']])>
                            <td class="font-medium">{{ $name }}</td>
                            <td class="text-right">{{ $r['count'] }}</td>
                            <td class="text-right">{{ $r['guests'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ Format::money($r['value']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ Format::money($r['advance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-slate-500">No bookings in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endforeach

    <section class="card overflow-x-auto">
        <h2 class="px-4 pt-4 pb-2 font-semibold">By area <span class="text-xs font-normal text-slate-400">· hours booked</span></h2>
        <table class="table">
            <thead><tr><th>Area</th><th class="text-right">Bookings</th><th class="text-right">Hours</th><th class="text-right">Value</th><th class="w-1/4"></th></tr></thead>
            <tbody>
                @forelse ($byArea as $name => $r)
                    <tr>
                        <td class="font-medium">{{ $name }}</td>
                        <td class="text-right">{{ $r['count'] }}</td>
                        <td class="text-right">{{ $r['hours'] }}</td>
                        <td class="text-right whitespace-nowrap">{{ Format::money($r['value']) }}</td>
                        <td>@include('reports._bar', ['value' => $r['hours'], 'max' => $byArea->max('hours')])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-slate-500">No bookings in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="px-4 pt-4 pb-2 font-semibold">Cancellation reasons</h2>
        <table class="table">
            <thead><tr><th>Reason</th><th class="text-right">Bookings</th></tr></thead>
            <tbody>
                @forelse ($cancelReasons as $reason => $count)
                    <tr><td>{{ $reason }}</td><td class="text-right">{{ $count }}</td></tr>
                @empty
                    <tr><td colspan="2" class="py-6 text-center text-slate-500">No cancellations in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto xl:col-span-2">
        <h2 class="px-4 pt-4 pb-2 font-semibold">By event day</h2>
        <div class="max-h-96 overflow-y-auto">
            <table class="table">
                <thead><tr><th>Date</th><th class="text-right">Bookings</th><th class="text-right">Guests</th><th class="text-right">Value</th><th class="w-1/2"></th></tr></thead>
                <tbody>
                    @forelse ($byEventDay as $date => $r)
                        @php
                            $d = \Illuminate\Support\Carbon::parse($date);
                        @endphp
                        <tr @class(['bg-orange-50' => $d->isToday()])>
                            <td class="whitespace-nowrap">{{ $d->format('D, d M Y') }}</td>
                            <td class="text-right">{{ $r['count'] }}</td>
                            <td class="text-right">{{ $r['guests'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ Format::money($r['value']) }}</td>
                            <td>@include('reports._bar', ['value' => $r['value'], 'max' => $maxDay])</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-slate-500">No bookings in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
