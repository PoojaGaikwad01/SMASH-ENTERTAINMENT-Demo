{{-- Enquiries tab (ReportController::enquiryReport). Conversion = confirmed + completed. --}}
@php
    use App\Support\Format;
    $isAdmin = auth()->user()->isAdmin();
    $pct = fn ($part, $total) => $total ? round($part / $total * 100).'%' : '—';
    $cards = [
        ['Enquiries received', number_format($totals['total']), null],
        ['Converted', number_format($totals['converted']), $pct($totals['converted'], $totals['total']).' confirmed or completed'],
        ['Lost', number_format($totals['lost']), $pct($totals['lost'], $totals['total']).' of enquiries'],
        ['Pending reply', number_format($totals['pending']), 'New at an outlet, no reply yet'],
        ['Avg time to reply', Format::duration($totals['avg_reply']), 'From assignment to first reply'],
    ];
    if ($isAdmin && config('leads.agency_parsing')) {
        $cards[] = ['Needs review', number_format($totals['needs_review']), 'Agency leads to fix'];
    }
    $maxDay = $byDay->max() ?: 0;
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
    @foreach (array_filter(['By source' => $bySource, 'By outlet' => $isAdmin ? $byBranch : null]) as $heading => $rows)
        <section class="card overflow-x-auto">
            <h2 class="px-4 pt-4 pb-2 font-semibold">{{ $heading }}</h2>
            <table class="table">
                <thead><tr><th>{{ $heading === 'By source' ? 'Source' : 'Outlet' }}</th><th class="text-right">Enquiries</th><th class="text-right">Converted</th><th class="text-right">Pending</th><th class="text-right">Replied</th><th class="text-right">Avg reply</th><th class="text-right">Reminders</th></tr></thead>
                <tbody>
                    @foreach ($rows as $name => $r)
                        <tr @class(['text-slate-400' => ! $r['total']])>
                            <td class="font-medium">{{ $name }}</td>
                            <td class="text-right">{{ $r['total'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ $r['converted'] }} <span class="text-xs text-slate-400">{{ $pct($r['converted'], $r['total']) }}</span></td>
                            <td class="text-right">{{ $r['pending'] }}</td>
                            <td class="text-right">{{ $r['replied'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ Format::duration($r['avg_reply']) }}</td>
                            <td class="text-right">{{ $r['reminders'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endforeach

    <section class="card overflow-x-auto">
        <h2 class="px-4 pt-4 pb-2 font-semibold">By enquiry type</h2>
        <table class="table">
            <thead><tr><th>Type</th><th class="text-right">Enquiries</th><th class="text-right">Converted</th><th class="text-right">Lost</th><th class="text-right">Conversion</th></tr></thead>
            <tbody>
                @foreach ($byType as $name => $r)
                    <tr @class(['text-slate-400' => ! $r['total']])>
                        <td class="font-medium">{{ $name }}</td>
                        <td class="text-right">{{ $r['total'] }}</td>
                        <td class="text-right">{{ $r['converted'] }}</td>
                        <td class="text-right">{{ $r['lost'] }}</td>
                        <td class="text-right">{{ $pct($r['converted'], $r['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="px-4 pt-4 pb-2 font-semibold">By status</h2>
        <table class="table">
            <thead><tr><th>Status</th><th class="text-right">Enquiries</th></tr></thead>
            <tbody>
                @foreach ($byStatus as $name => $count)
                    <tr @class(['text-slate-400' => ! $count])><td class="font-medium">{{ $name }}</td><td class="text-right">{{ $count }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="px-4 pt-4 pb-2 font-semibold">Lost reasons</h2>
        <table class="table">
            <thead><tr><th>Reason</th><th class="text-right">Enquiries</th><th class="w-1/3"></th></tr></thead>
            <tbody>
                @forelse ($lostReasons as $reason => $count)
                    <tr><td>{{ $reason }}</td><td class="text-right">{{ $count }}</td><td>@include('reports._bar', ['value' => $count, 'max' => $lostReasons->max()])</td></tr>
                @empty
                    <tr><td colspan="3" class="py-6 text-center text-slate-500">No lost enquiries in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="card overflow-x-auto">
        <h2 class="px-4 pt-4 pb-2 font-semibold">By day</h2>
        <div class="max-h-96 overflow-y-auto">
            <table class="table">
                <thead><tr><th>Date</th><th class="text-right">Enquiries</th><th class="w-1/2"></th></tr></thead>
                <tbody>
                    @forelse ($byDay as $date => $count)
                        <tr>
                            <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($date)->format('D, d M Y') }}</td>
                            <td class="text-right">{{ $count }}</td>
                            <td>@include('reports._bar', ['value' => $count, 'max' => $maxDay])</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-slate-500">No enquiries in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
