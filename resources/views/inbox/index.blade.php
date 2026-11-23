@php
    use App\Enums\EnquiryType;
    use App\Enums\LeadSource;
    use App\Enums\LeadStatus;
    use App\Support\Format;
    $isAdmin = auth()->user()->isAdmin();
    $query = request()->except('page');
    $hasExtraFilters = collect(['source', 'branch_id', 'enquiry_type', 'from', 'to'])->contains(fn ($f) => request()->filled($f));
    $dueCount = \App\Models\Lead::visibleTo(auth()->user())->followUpsDue()->count();
@endphp
<x-layouts.app :title="$due ? 'Follow-ups due' : 'Enquiries'" :flush="true">
<div class="flex h-full">

    {{-- ===== Lead list (hidden on mobile when a lead is open) ===== --}}
    <section x-data="{ selected: [] }" @class(['w-full flex-col border-r border-slate-200 bg-white md:flex md:w-96 md:shrink-0', 'hidden' => $lead, 'flex' => ! $lead])>
        <div class="space-y-2 border-b border-slate-200 p-3">
            {{-- View: all enquiries / follow-ups due today (incl. overdue) --}}
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm font-medium">
                <a href="{{ route('inbox', array_diff_key($query, ['view' => 1, 'status' => 1])) }}"
                   @class(['rounded-md px-2 py-1 text-center', 'bg-white shadow-sm' => ! $due, 'text-slate-500 hover:text-slate-700' => $due])>All enquiries</a>
                <a href="{{ route('inbox', ['view' => 'due'] + array_diff_key($query, ['status' => 1])) }}"
                   @class(['rounded-md px-2 py-1 text-center', 'bg-white shadow-sm' => $due, 'text-slate-500 hover:text-slate-700' => ! $due])>
                    ⏰ Follow-ups due @if ($dueCount)<span class="ml-0.5 rounded-full bg-rose-600 px-1.5 text-xs text-white">{{ $dueCount }}</span>@endif
                </a>
            </div>
            <form method="GET" action="{{ route('inbox') }}" x-data="{ open: @js($hasExtraFilters) }">
                @if (request()->filled('status')) <input type="hidden" name="status" value="{{ request('status') }}"> @endif
                @if ($due) <input type="hidden" name="view" value="due"> @endif
                <div class="flex gap-2">
                    <input name="q" value="{{ request('q') }}" type="search" placeholder="Search name or phone" class="input">
                    <button type="button" @click="open = !open" :class="open && 'bg-slate-100'" class="btn btn-secondary px-2.5" aria-label="Filters">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
                    </button>
                    <a href="{{ route('enquiries.create') }}" class="btn btn-primary shrink-0 px-3" title="New enquiry (phone call / walk-in)">+ New</a>
                </div>
                <div x-show="open" x-cloak class="mt-2 grid grid-cols-2 gap-2">
                    <select name="enquiry_type" onchange="this.form.submit()" class="input" aria-label="Enquiry type">
                        <option value="">All types</option>
                        @foreach (EnquiryType::cases() as $t)
                            <option value="{{ $t->value }}" @selected(request('enquiry_type') === $t->value)>{{ $t->label() }}</option>
                        @endforeach
                    </select>
                    <select name="source" onchange="this.form.submit()" class="input" aria-label="Source">
                        <option value="">All sources</option>
                        @foreach (LeadSource::options() as $s)
                            <option value="{{ $s->value }}" @selected(request('source') === $s->value)>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    @if ($isAdmin)
                        <select name="branch_id" onchange="this.form.submit()" class="input">
                            <option value="">All outlets</option>
                            <option value="none" @selected(request('branch_id') === 'none')>Unassigned</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <label class="block text-xs text-slate-500">Received from
                        <input type="date" name="from" value="{{ request('from') }}" onchange="this.form.submit()" class="input mt-0.5">
                    </label>
                    <label class="block text-xs text-slate-500">to
                        <input type="date" name="to" value="{{ request('to') }}" onchange="this.form.submit()" class="input mt-0.5">
                    </label>
                    @if ($hasExtraFilters)
                        <a href="{{ route('inbox', array_filter(['view' => $due ? 'due' : null, 'status' => request('status'), 'q' => request('q')])) }}" class="col-span-2 text-center text-xs text-orange-700 hover:underline">Clear filters</a>
                    @endif
                </div>
            </form>

            {{-- Status chips with counts --}}
            <div class="-mx-3 flex gap-1.5 overflow-x-auto px-3 pb-0.5 scrollbar-none">
                @php($chips = [null => 'All'] + collect(LeadStatus::options())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())
                @foreach ($chips as $value => $label)
                    @php($active = (string) request('status') === (string) $value)
                    <a href="{{ route('inbox', array_filter(['status' => $value] + $query)) }}"
                       @class(['shrink-0 rounded-full px-3 py-1 text-xs font-medium whitespace-nowrap',
                               'bg-slate-800 text-white' => $active, 'bg-slate-100 text-slate-600 hover:bg-slate-200' => ! $active])>
                        {{ $label }}
                        <span class="opacity-70">{{ $value ? ($statusCounts[$value] ?? 0) : $statusCounts->sum() }}</span>
                    </a>
                @endforeach
            </div>

            @if ($isAdmin && $leads->isNotEmpty())
                <label class="flex items-center gap-2 text-xs text-slate-500">
                    <input type="checkbox" class="rounded border-slate-300 text-orange-600"
                           :checked="selected.length === {{ $leads->count() }}"
                           @change="selected = $event.target.checked ? @js($leads->pluck('id')->map(fn ($id) => (string) $id)) : []">
                    Select all on this page (for bulk assign)
                </label>
            @endif
        </div>

        @unless ($lead)
            <div class="px-3 pt-3 empty:hidden"><x-flash /></div>
        @endunless

        <div class="flex-1 divide-y divide-slate-100 overflow-y-auto">
            @forelse ($leads as $item)
                <div @class(['flex', 'bg-orange-50' => $lead?->id === $item->id, 'hover:bg-slate-50' => $lead?->id !== $item->id])>
                @if ($isAdmin)
                    <label class="flex cursor-pointer items-center pl-3">
                        <input type="checkbox" value="{{ $item->id }}" x-model="selected" class="rounded border-slate-300 text-orange-600" aria-label="Select {{ $item->display_name }}">
                    </label>
                @endif
                <a href="{{ route('inbox.show', ['lead' => $item->id] + request()->query()) }}" class="flex min-w-0 flex-1 gap-3 px-3 py-3">
                    <x-lead-avatar :lead="$item" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate font-medium">{{ $item->display_name }}</span>
                            @if ($fu = $item->status->isOpen() || $item->status === LeadStatus::Confirmed ? $item->next_follow_up_at : null)
                                <span title="Next follow-up {{ $fu->format('D d M, g:i a') }}" @class(['shrink-0 rounded px-1.5 text-xs font-medium',
                                    'bg-rose-100 text-rose-700' => $fu->isPast(), 'bg-amber-100 text-amber-800' => ! $fu->isPast() && $fu->isToday(),
                                    'text-slate-400' => ! $fu->isPast() && ! $fu->isToday()])>⏰ {{ $fu->isToday() ? $fu->format('g:i a') : $fu->format('d M') }}</span>
                            @else
                                <span class="shrink-0 text-xs text-slate-400">{{ Format::shortTime($item->last_activity_at) }}</span>
                            @endif
                        </div>
                        <p class="truncate text-sm text-slate-500">
                            @if ($item->latestMessage?->direction === 'out')<span class="text-slate-400">You: </span>@endif{{ $item->latestMessage?->body ?? ($item->latestMessage?->attachment_url ? '📎 Attachment' : ($item->form_name ? 'Form: '.$item->form_name : '—')) }}
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-1">
                            <span class="badge {{ $item->source->color() }}">{{ $item->source->short() }}</span>
                            <span class="badge {{ $item->status->color() }}">{{ $item->status->label() }}</span>
                            @if ($item->enquiry_type !== EnquiryType::General)<span class="text-xs text-slate-500">{{ $item->enquiry_type->label() }}</span>@endif
                            @if ($isAdmin)
                                <span class="truncate text-xs text-slate-400">· {{ $item->branch?->name ?? 'Unassigned' }}</span>
                            @endif
                        </div>
                    </div>
                </a>
                </div>
            @empty
                <div class="p-8 text-center text-sm text-slate-500">
                    {{ $due ? 'No follow-ups due today. 🎉' : 'No enquiries found.' }}
                    @unless ($isAdmin)<div class="mt-1 text-xs">Only enquiries of {{ auth()->user()->branch?->name ?? 'your outlet' }} appear here.</div>@endunless
                </div>
            @endforelse
        </div>

        @if ($isAdmin)
            <form x-show="selected.length" x-cloak method="POST" action="{{ route('leads.bulk-assign') }}"
                  class="flex flex-wrap items-center gap-2 border-t border-orange-200 bg-orange-50 px-3 py-2">
                @csrf
                <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                <span class="shrink-0 text-sm font-medium"><span x-text="selected.length"></span> selected</span>
                <select name="branch_id" required class="input min-w-0 flex-1 py-1.5">
                    <option value="">Assign to outlet…</option>
                    @foreach ($branches->where('is_active', true) as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary py-1.5">Assign</button>
                <button type="button" @click="selected = []" class="text-xs text-slate-500 hover:underline">Clear</button>
            </form>
        @endif

        @if ($leads->hasPages())
            <div class="flex items-center justify-between border-t border-slate-200 px-3 py-2 text-sm">
                <a href="{{ $leads->previousPageUrl() }}" @class(['btn btn-secondary px-3 py-1', 'pointer-events-none opacity-40' => $leads->onFirstPage()])>‹ Prev</a>
                <span class="text-xs text-slate-500">{{ $leads->firstItem() }}–{{ $leads->lastItem() }} of {{ $leads->total() }}</span>
                <a href="{{ $leads->nextPageUrl() }}" @class(['btn btn-secondary px-3 py-1', 'pointer-events-none opacity-40' => ! $leads->hasMorePages()])>Next ›</a>
            </div>
        @endif
    </section>

    {{-- ===== Thread (hidden on mobile when no lead is open) ===== --}}
    <section @class(['min-w-0 flex-1 flex-col bg-slate-100 md:flex', 'flex' => $lead, 'hidden' => ! $lead])>
        @if ($lead)
            @include('inbox._thread')
        @else
            <div class="m-auto p-8 text-center text-sm text-slate-500">
                <svg class="mx-auto mb-2 size-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                Select a lead to see details and messages.
            </div>
        @endif
    </section>
</div>
</x-layouts.app>
