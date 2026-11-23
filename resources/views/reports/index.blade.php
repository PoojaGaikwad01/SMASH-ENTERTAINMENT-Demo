@php
    use App\Enums\LeadSource;
    $isAdmin = auth()->user()->isAdmin();
    $keep = request()->only('from', 'to', 'branch_id'); // carried across tabs
@endphp
<x-layouts.app title="Reports">
    <x-slot:actions>
        <nav class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" aria-label="Report">
            @foreach (['enquiries' => 'Enquiries', 'bookings' => 'Bookings'] as $key => $label)
                <a href="{{ route('reports', $keep + ['tab' => $key]) }}"
                   @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white shadow-sm text-slate-900' => $tab === $key, 'text-slate-600 hover:text-slate-900' => $tab !== $key])>{{ $label }}</a>
            @endforeach
        </nav>
        <a href="{{ route('reports.export', request()->query() + ['tab' => $tab]) }}" class="btn btn-secondary">⬇ Export CSV</a>
    </x-slot:actions>

    <form method="GET" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <label class="block"><span class="label">{{ $tab === 'bookings' ? 'Event from' : 'Received from' }}</span><input type="date" name="from" value="{{ $from->toDateString() }}" class="input"></label>
        <label class="block"><span class="label">{{ $tab === 'bookings' ? 'Event to' : 'Received to' }}</span><input type="date" name="to" value="{{ $to->toDateString() }}" class="input"></label>
        @if ($tab === 'enquiries')
            <label class="block"><span class="label">Source</span>
                <select name="source" class="input">
                    <option value="">All sources</option>
                    @foreach (LeadSource::options() as $s)
                        <option value="{{ $s->value }}" @selected(request('source') === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        @if ($isAdmin)
            <label class="block"><span class="label">Outlet</span>
                <select name="branch_id" class="input">
                    <option value="">All outlets</option>
                    @if ($tab === 'enquiries')<option value="none" @selected(request('branch_id') === 'none')>Unassigned</option>@endif
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="flex gap-2">
            <button class="btn btn-primary">Apply</button>
            <a href="{{ route('reports', ['tab' => $tab]) }}" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    @unless ($isAdmin)
        <p class="mb-4 text-sm text-slate-500">Showing {{ $tab }} for {{ auth()->user()->branch?->name ?? 'your outlet' }} only.</p>
    @endunless

    @include($tab === 'bookings' ? 'reports._bookings' : 'reports._enquiries')
</x-layouts.app>
