@php
    use App\Enums\ActivityType;
    use App\Enums\EnquiryType;
    use App\Support\Format;
    $fields = collect($lead->raw_payload['field_data'] ?? [])
        ->map(fn ($f) => ['name' => ucfirst(str_replace('_', ' ', $f['name'] ?? '')), 'value' => implode(', ', (array) ($f['values'] ?? []))]);
    $details = array_filter([
        'Phone' => $lead->phone,
        'Email' => $lead->email,
        'City' => $lead->city,
        'Source' => $lead->source->label(),
        'Enquiry' => $lead->enquiry_type->label(),
        'Preferred date' => $lead->preferred_date?->format('D, d M Y'),
        'Guests' => $lead->guest_count,
        'Outlet' => $lead->branch ? $lead->branch->name.' ('.$lead->branch->city.')' : 'Unassigned',
        'Assigned' => $lead->assigned_at ? $lead->assigned_at->format('d M, g:i a').($lead->assignedBy ? ' by '.$lead->assignedBy->name : '') : null,
        'Replied' => $lead->replied_at ? $lead->replied_at->format('d M, g:i a').($lead->assigned_at ? ' ('.Format::duration($lead->assigned_at->diffInMinutes($lead->replied_at)).' after assignment)' : '') : null,
        'Reminders' => $lead->reminders->isNotEmpty() ? $lead->reminders->count().' sent · last '.$lead->reminders->sortByDesc('sent_at')->first()->sent_at->diffForHumans() : null,
        'Agency' => $lead->agency_name,
        'Campaign' => $lead->campaign_name,
        'Ad' => $lead->ad_name,
        'Form' => $lead->form_name,
        'Received' => $lead->created_at->format('d M Y, g:i a'),
        'Entered by' => $lead->createdBy?->name,
        'Follow up' => $lead->next_follow_up_at?->format('d M, g:i a'),
        'Notes' => $lead->notes,
    ]);
@endphp

{{-- Header --}}
<header class="flex items-center gap-3 border-b border-slate-200 bg-white px-3 py-2.5">
    <a href="{{ route('inbox', request()->query()) }}" class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 md:hidden" aria-label="Back to list">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
    </a>
    <x-lead-avatar :lead="$lead" />
    <div class="min-w-0 flex-1">
        <div class="truncate font-semibold">{{ $lead->display_name }}</div>
        <div class="truncate text-xs text-slate-500">
            @if ($lead->phone)<a href="tel:{{ $lead->phone }}" class="hover:underline">{{ $lead->phone }}</a>@else No phone @endif
            · {{ $lead->source->label() }}
        </div>
    </div>
    <span class="badge {{ $lead->status->color() }}">{{ $lead->status->label() }}</span>
</header>

@can('update', $lead)
    @include('inbox._pipeline')
@endcan

{{-- Admin: assign / reassign --}}
@if (auth()->user()->isAdmin())
    @php $blocked = $lead->status === \App\Enums\LeadStatus::NeedsReview; @endphp
    <form method="POST" action="{{ route('leads.assign', $lead) }}" class="flex items-center gap-2 border-b border-slate-200 bg-white px-4 py-2">
        @csrf
        <label for="assign_branch" class="shrink-0 text-xs text-slate-500">{{ $lead->branch ? 'Outlet' : 'Assign to' }}</label>
        <select id="assign_branch" name="branch_id" required class="input py-1.5" @disabled($blocked)>
            <option value="">{{ $blocked ? 'Review this enquiry first' : 'Select outlet…' }}</option>
            @foreach ($branches->where('is_active', true) as $b)
                <option value="{{ $b->id }}" @selected($lead->branch_id === $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
        <button class="btn btn-secondary shrink-0 py-1.5" @disabled($blocked)>{{ $lead->branch ? 'Reassign' : 'Assign' }}</button>
    </form>
@endif

{{-- Details (collapsed by default on phones) --}}
<div x-data="{ open: window.innerWidth >= 768 }" class="border-b border-slate-200 bg-white">
    <button type="button" @click="open = !open" class="flex w-full items-center justify-between px-4 py-2 text-sm font-medium text-slate-600">
        Enquiry details
        <svg :class="open && 'rotate-180'" class="size-4 transition" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
    </button>
    <dl x-show="open" x-cloak class="grid grid-cols-2 gap-x-4 gap-y-2 px-4 pb-3 text-sm lg:grid-cols-3">
        @foreach ($details as $label => $value)
            <div class="min-w-0">
                <dt class="text-xs text-slate-400">{{ $label }}</dt>
                <dd class="truncate" title="{{ $value }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</div>

{{-- Edit details: admin or the outlet's staff (opens automatically for "Needs review") --}}
@can('update', $lead)
    @php $review = $lead->status === \App\Enums\LeadStatus::NeedsReview; @endphp
    <div x-data="{ edit: @js($review || $errors->any()) }" @class(['border-b px-4 py-2', 'border-amber-200 bg-amber-50' => $review, 'border-slate-200 bg-white' => ! $review])>
        <div class="flex items-center justify-between gap-2 text-sm">
            <span @class(['font-medium', 'text-amber-800' => $review, 'text-slate-600' => ! $review])>
                {{ $review ? 'Needs review — check the original message below and fix the details.' : 'Edit enquiry details' }}
            </span>
            <button type="button" @click="edit = !edit" class="shrink-0 text-xs font-medium text-orange-700 hover:underline" x-text="edit ? 'Close' : 'Edit'"></button>
        </div>
        <form x-show="edit" x-cloak method="POST" action="{{ route('leads.update', $lead) }}" class="mt-2 grid grid-cols-2 gap-2 pb-1 lg:grid-cols-4">
            @csrf @method('PATCH')
            @foreach (array_filter(['name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'city' => 'City',
                                    'agency_name' => $lead->source === \App\Enums\LeadSource::WhatsappAgency ? 'Agency' : null]) as $field => $label)
                <label class="block">
                    <span class="text-xs text-slate-500">{{ $label }}</span>
                    <input name="{{ $field }}" value="{{ old($field, $lead->{$field}) }}" @if ($field === 'phone') type="tel" @elseif ($field === 'email') type="email" @endif class="input py-1.5">
                </label>
            @endforeach
            <label class="block">
                <span class="text-xs text-slate-500">Enquiry for</span>
                <select name="enquiry_type" class="input py-1.5">
                    @foreach (EnquiryType::cases() as $t)
                        <option value="{{ $t->value }}" @selected(old('enquiry_type', $lead->enquiry_type->value) === $t->value)>{{ $t->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-xs text-slate-500">Preferred date</span>
                <input type="date" name="preferred_date" value="{{ old('preferred_date', $lead->preferred_date?->toDateString()) }}" class="input py-1.5">
            </label>
            <label class="block">
                <span class="text-xs text-slate-500">Guests</span>
                <input type="number" name="guest_count" min="1" max="2000" value="{{ old('guest_count', $lead->guest_count) }}" class="input py-1.5">
            </label>
            <label class="col-span-2 block lg:col-span-4">
                <span class="text-xs text-slate-500">Notes</span>
                <textarea name="notes" rows="2" maxlength="2000" class="input py-1.5">{{ old('notes', $lead->notes) }}</textarea>
            </label>
            <div class="col-span-2 lg:col-span-4">
                <button class="btn btn-primary py-1.5">{{ $review ? 'Save & mark reviewed' : 'Save' }}</button>
            </div>
        </form>
    </div>
@endcan

<x-flash />

{{-- Messages --}}
<div class="flex-1 space-y-2 overflow-y-auto p-3 md:p-4" x-data x-init="$el.scrollTop = $el.scrollHeight">
    @if ($lead->source->isLeadAd())
        <div class="mx-auto max-w-md rounded-xl bg-white p-4 text-sm shadow-sm">
            <div class="mb-2 font-medium">Lead form submitted · {{ $lead->created_at->format('d M, g:i a') }}</div>
            @if ($fields->isNotEmpty())
                <dl class="space-y-1.5">
                    @foreach ($fields as $f)
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ $f['name'] }}</dt><dd class="text-right font-medium">{{ $f['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif
            <p class="mt-3 text-xs text-slate-500">Lead Ads have no chat thread — call or WhatsApp the customer.</p>
        </div>
    @elseif (! $lead->source->channel())
        <div class="mx-auto max-w-md rounded-xl bg-white p-4 text-sm shadow-sm">
            <div class="font-medium">Enquiry via {{ $lead->source->label() }} · {{ $lead->created_at->format('d M, g:i a') }}</div>
            @if ($lead->notes)<p class="mt-2 whitespace-pre-line text-slate-700">{{ $lead->notes }}</p>@endif
            <p class="mt-3 text-xs text-slate-500">No chat thread — call or WhatsApp the customer.</p>
        </div>
    @endif

    @php
        // One chronological stream: customer conversation + calls / notes / status changes.
        $timeline = $lead->messages->map(fn ($m) => ['at' => $m->created_at, 'msg' => $m])
            ->concat($lead->activities->map(fn ($a) => ['at' => $a->created_at, 'act' => $a]))
            ->sortBy(fn ($e) => $e['at']->getTimestamp())->values();
    @endphp
    @forelse ($timeline->groupBy(fn ($e) => $e['at']->toDateString()) as $entries)
        <div class="py-1 text-center">
            <span class="rounded-full bg-white/80 px-3 py-1 text-xs text-slate-500 shadow-sm">{{ Format::dayLabel($entries->first()['at']) }}</span>
        </div>
        @foreach ($entries as $e)
            @if (isset($e['act']))
                @php $a = $e['act']; @endphp
                @if (in_array($a->type, [ActivityType::StatusChange, ActivityType::Message, ActivityType::Booking], true))
                    <div class="py-1 text-center">
                        <span class="inline-block rounded-full bg-slate-200/70 px-3 py-1 text-xs text-slate-600">{{ $a->type->icon() }} {{ $a->body }} · {{ $a->user?->name ?? 'System' }} · {{ $a->created_at->format('g:i a') }}</span>
                    </div>
                @else
                    <div @class(['mx-auto max-w-md rounded-xl border px-3 py-2 text-sm shadow-sm',
                                 'border-sky-200 bg-sky-50' => $a->type === ActivityType::Call, 'border-amber-200 bg-amber-50' => $a->type === ActivityType::Note])>
                        <div class="flex justify-between gap-2 text-xs text-slate-500">
                            <span class="font-medium">{{ $a->type->icon() }} {{ $a->type === ActivityType::Call ? 'Call logged' : 'Note' }} · {{ $a->user?->name ?? 'System' }}</span>
                            <span>{{ $a->created_at->format('g:i a') }}</span>
                        </div>
                        @if (filled($a->body))<p class="mt-1 wrap-break-word whitespace-pre-line">{{ $a->body }}</p>@endif
                    </div>
                @endif
                @continue
            @endif
            @php $m = $e['msg']; @endphp
            @if ($m->channel === 'note')
                <div class="py-1 text-center">
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs text-emerald-700">✔ {{ $m->body }}@if ($m->sender) by {{ $m->sender->name }}@endif · {{ $m->created_at->format('g:i a') }}</span>
                </div>
                @continue
            @endif
            <div @class(['flex', 'justify-end' => ! $m->isInbound()])>
                <div @class(['max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                             'rounded-tl-sm bg-white' => $m->isInbound(), 'rounded-tr-sm bg-emerald-100' => ! $m->isInbound()])>
                    @if ($m->attachment_url)
                        <a href="{{ route('attachments.show', $m) }}" target="_blank" rel="noopener" class="mb-1 flex items-center gap-2 rounded-lg bg-black/5 px-2 py-1.5 text-xs font-medium hover:bg-black/10">
                            📎 <span class="truncate">{{ basename(parse_url($m->attachment_url, PHP_URL_PATH) ?: 'Attachment') }}</span>
                        </a>
                    @endif
                    @if (filled($m->body))<p class="wrap-break-word whitespace-pre-line">{{ $m->body }}</p>@endif
                    <div class="mt-0.5 text-right text-[11px] text-slate-400">
                        @if ($m->sender){{ $m->sender->name }} · @endif{{ $m->created_at->format('g:i a') }}
                    </div>
                </div>
            </div>
        @endforeach
    @empty
        @if ($lead->source->channel())
            <p class="py-8 text-center text-sm text-slate-500">No messages yet.</p>
        @endif
    @endforelse
</div>

{{-- Reply / log call / add note --}}
@php
    $channelLabels = ['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram'];
    $waText = 'Hi '.($lead->name ? strtok($lead->name, ' ') : 'there').', this is Smash Entertainment'
        .($lead->branch ? ' '.$lead->branch->name : '').'. Thanks for your enquiry!';
    $canReply = auth()->user()->can('reply', $lead);
    $tab = old('type') ?: ($window['channel'] && $canReply ? 'reply' : 'call');
    $tabBtn = 'rounded-full px-3 py-1';
@endphp
@can('update', $lead)
    <footer x-data="{ tab: @js($tab), none: false }" class="border-t border-slate-200 bg-white p-2 md:p-3">
        @unless ($lead->branch_id)
            <p class="mb-2 px-1 text-xs text-slate-500">Assign this enquiry to an outlet so its staff can reply.</p>
        @endunless
        <div class="mb-2 flex flex-wrap gap-1 text-xs font-medium">
            @if ($window['channel'])
                <button type="button" @click="tab = 'reply'" :class="tab === 'reply' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600'" class="{{ $tabBtn }}">💬 Reply</button>
            @endif
            <button type="button" @click="tab = 'call'" :class="tab === 'call' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600'" class="{{ $tabBtn }}">📞 Log call</button>
            <button type="button" @click="tab = 'note'" :class="tab === 'note' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600'" class="{{ $tabBtn }}">📝 Add note</button>
        </div>

        @if ($window['channel'])
            <div x-show="tab === 'reply'" @if ($tab !== 'reply') x-cloak @endif>
                @if ($canReply)
                    <form method="POST" action="{{ route('leads.reply', $lead) }}" x-data="{ sending: false }" @submit="sending = true" class="flex items-end gap-2">
                        @csrf
                        <textarea name="body" rows="1" required maxlength="2000" @disabled(! $window['open'])
                                  placeholder="{{ $window['open'] ? 'Reply via '.$channelLabels[$window['channel']].'…' : '24h window closed' }}"
                                  x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 128) + 'px'"
                                  @keydown.ctrl.enter.prevent="$el.form.requestSubmit()"
                                  class="input resize-none disabled:bg-slate-100 disabled:text-slate-400">{{ old('type') ? '' : old('body') }}</textarea>
                        <button class="btn btn-primary" :disabled="sending || {{ $window['open'] ? 'false' : 'true' }}" @disabled(! $window['open'])>Send</button>
                    </form>
                    <p class="mt-1 px-1 text-[11px] text-slate-400">
                        @if ($window['open'])
                            Via {{ $channelLabels[$window['channel']] }} · 24h window closes {{ $window['closes_at']->diffForHumans() }}
                        @else
                            24h window closed {{ $window['closes_at']->diffForHumans() }} — free-text replies are only allowed within 24h of the customer's last message.
                        @endif
                    </p>
                @else
                    <p class="px-1 text-sm text-slate-500">Replies are available once this enquiry has an outlet.</p>
                @endif
            </div>
        @endif

        <form x-show="tab !== 'reply'" @if ($tab === 'reply') x-cloak @endif method="POST" action="{{ route('leads.activities.store', $lead) }}" class="space-y-2">
            @csrf
            <input type="hidden" name="type" :value="tab === 'note' ? 'note' : 'call'" value="{{ $tab === 'note' ? 'note' : 'call' }}">
            <textarea name="body" rows="2" maxlength="2000" class="input resize-none" aria-label="Call summary or note"
                      :required="tab === 'note'"
                      :placeholder="tab === 'note' ? 'Write a note…' : 'Call summary (optional) — e.g. shared prices, customer will confirm date'"></textarea>
            <div class="flex flex-wrap items-center gap-2">
                <label for="activity_follow_up" class="text-xs text-slate-500">Next follow-up</label>
                <input id="activity_follow_up" type="datetime-local" name="next_follow_up_at" :disabled="none"
                       value="{{ now()->addHours(config('leads.follow_up_default_hours'))->format('Y-m-d\TH:i') }}" class="input w-auto py-1.5 disabled:opacity-40">
                <label class="flex items-center gap-1 text-xs text-slate-500">
                    <input type="checkbox" name="no_follow_up" value="1" x-model="none" class="rounded border-slate-300 text-orange-600"> No follow-up
                </label>
                <button class="btn btn-primary ml-auto py-1.5" x-text="tab === 'note' ? 'Add note' : 'Log call'">Save</button>
            </div>
        </form>

        <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
            @if ($lead->phone)
                <a href="tel:{{ $lead->phone }}" class="btn btn-secondary py-1.5">📞 Call</a>
                <a href="https://wa.me/{{ ltrim($lead->phone, '+') }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener" class="btn btn-secondary py-1.5">WhatsApp</a>
            @elseif (! $window['channel'])
                <span class="text-sm text-slate-500">No phone number on this enquiry.</span>
            @endif
            @if (auth()->user()->isAdmin() && $lead->awaitingReply())
                <form method="POST" action="{{ route('leads.remind', $lead) }}" class="ml-auto">
                    @csrf
                    <button class="btn btn-secondary py-1.5" title="Notify this outlet's staff">🔔 Send reminder</button>
                </form>
            @endif
        </div>
    </footer>
@endcan
