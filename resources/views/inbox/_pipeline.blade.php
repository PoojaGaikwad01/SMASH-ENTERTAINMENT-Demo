{{-- Pipeline bar: status buttons (lost needs a reason) + next follow-up. EnquiryPipelineController, LeadPolicy::update. --}}
@php
    use App\Enums\LeadStatus;
    $fu = $lead->next_follow_up_at;
    $pill = 'rounded-full border border-slate-300 bg-white px-2.5 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50';
@endphp
<div x-data="{ lost: @js($errors->has('lost_reason')), editFu: false }" class="space-y-2 border-b border-slate-200 bg-white px-4 py-2">
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="mr-1 text-xs text-slate-500">Status</span>
        @foreach (LeadStatus::options() as $s)
            @continue($s === LeadStatus::NeedsReview)
            @if ($s === $lead->status)
                <span class="badge {{ $s->color() }} ring-2 ring-slate-300" aria-current="true">{{ $s->label() }}</span>
            @elseif ($s === LeadStatus::Lost)
                <button type="button" @click="lost = !lost" class="{{ $pill }}">Lost…</button>
            @else
                <form method="POST" action="{{ route('leads.status', $lead) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ $s->value }}">
                    <button class="{{ $pill }}">{{ $s->label() }}</button>
                </form>
            @endif
        @endforeach
    </div>

    <form x-show="lost" x-cloak method="POST" action="{{ route('leads.status', $lead) }}" class="flex flex-wrap items-center gap-2">
        @csrf
        <input type="hidden" name="status" value="lost">
        <input name="lost_reason" list="lost-reasons" required maxlength="255" value="{{ old('lost_reason') }}" placeholder="Why was it lost? (required)" aria-label="Lost reason" class="input min-w-0 flex-1 py-1.5">
        <datalist id="lost-reasons">
            @foreach (\App\Http\Controllers\EnquiryPipelineController::LOST_REASONS as $r)
                <option value="{{ $r }}"></option>
            @endforeach
        </datalist>
        <button class="btn btn-danger py-1.5">Mark lost</button>
    </form>
    @if ($lead->status === LeadStatus::Lost && $lead->lost_reason)
        <p class="text-xs text-slate-500">Lost reason: <span class="font-medium text-slate-700">{{ $lead->lost_reason }}</span></p>
    @endif

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-xs text-slate-500">Next follow-up</span>
        @if ($fu)
            <span @class(['font-medium', 'text-rose-700' => $fu->isPast(), 'text-amber-700' => ! $fu->isPast() && $fu->isToday()])>
                ⏰ {{ $fu->format('D d M, g:i a') }} <span class="font-normal text-slate-400">({{ $fu->diffForHumans() }})</span>
            </span>
        @else
            <span class="text-slate-400">None</span>
        @endif
        <button type="button" @click="editFu = !editFu" class="text-xs font-medium text-orange-700 hover:underline"
                x-text="editFu ? 'Cancel' : @js($fu ? 'Change' : 'Set')">{{ $fu ? 'Change' : 'Set' }}</button>
    </div>
    <form x-show="editFu" x-cloak method="POST" action="{{ route('leads.follow-up', $lead) }}" class="flex flex-wrap items-center gap-2">
        @csrf @method('PATCH')
        <input type="datetime-local" name="next_follow_up_at" aria-label="Next follow-up"
               value="{{ ($fu && $fu->isFuture() ? $fu : now()->addHours(config('leads.follow_up_default_hours')))->format('Y-m-d\TH:i') }}" class="input w-auto py-1.5">
        <button class="btn btn-secondary py-1.5">Save</button>
        @if ($fu)
            <button name="next_follow_up_at" value="" class="text-xs text-slate-500 hover:underline">Clear follow-up</button>
        @endif
    </form>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-xs text-slate-500">Bookings</span>
        @foreach ($lead->bookings as $b)
            <a href="{{ route('bookings.show', $b) }}" class="badge {{ $b->status->color() }} hover:underline" title="{{ $b->status->label() }}">
                {{ $b->booking_code }} · {{ $b->event_date->format('d M') }} {{ substr($b->start_time, 0, 5) }}
            </a>
        @endforeach
        @if ($lead->branch_id)
            <a href="{{ route('bookings.create', $lead) }}" class="text-xs font-medium text-orange-700 hover:underline">+ Create booking</a>
        @else
            <span class="text-xs text-slate-400">Assign an outlet to create a booking</span>
        @endif
    </div>
</div>
