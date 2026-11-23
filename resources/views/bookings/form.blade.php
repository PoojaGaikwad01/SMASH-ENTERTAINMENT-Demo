{{-- Create / edit a booking for an enquiry. Totals and availability are previewed live; BookingService re-checks everything. --}}
@php
    use App\Support\Format;
    $isEdit = $booking->exists;
    $pivot = $isEdit ? $booking->addons->keyBy('id') : collect();
    $cfg = [
        'availabilityUrl' => route('bookings.availability'),
        'ignore' => $booking->id,
        'areas' => $areas->mapWithKeys(fn ($a) => [$a->id => $a->capacity]),
        'packages' => $packages->mapWithKeys(fn ($p) => [$p->id => [
            'price' => (float) ($isEdit && (int) $booking->package_id === $p->id ? $booking->package_price : $p->price),
            'minutes' => $p->duration_minutes, 'max' => $p->max_guests, 'name' => $p->name,
        ]]),
        'addons' => $addons->mapWithKeys(fn ($a) => [$a->id => (float) ($pivot[$a->id]->pivot->price ?? $a->price)]),
        'initial' => [
            'area' => (string) old('area_id', $booking->area_id ?? ($areas->count() === 1 ? $areas->first()->id : '')),
            'pkg' => (string) old('package_id', $booking->package_id),
            'date' => old('event_date', $booking->event_date?->toDateString()),
            'start' => old('start_time', $booking->start_time ? substr($booking->start_time, 0, 5) : ''),
            'end' => old('end_time', $booking->end_time ? substr($booking->end_time, 0, 5) : ''),
            'guests' => old('guest_count', $booking->guest_count),
            'advance' => old('advance_paid', $isEdit ? (float) $booking->advance_paid : ''),
            'qty' => $addons->mapWithKeys(fn ($a) => [$a->id => (int) (old('addons.'.$a->id) ?? ($pivot[$a->id]->pivot->qty ?? 0))]),
        ],
    ];
@endphp
<x-layouts.app :title="$isEdit ? 'Edit booking '.$booking->booking_code : 'New booking'">
    <x-slot:actions>
        <a href="{{ $isEdit ? route('bookings.show', $booking) : route('inbox.show', $lead) }}" class="btn btn-secondary">Cancel</a>
    </x-slot:actions>

    <form method="POST" action="{{ $isEdit ? route('bookings.update', $booking) : route('bookings.store', $lead) }}"
          x-data="bookingForm(@js($cfg))" class="grid max-w-6xl gap-6 lg:grid-cols-3">
        @csrf
        @if ($isEdit)
            @method('PUT')
            <input type="hidden" name="advance_paid_was" value="{{ old('advance_paid_was', $booking->advance_paid) }}">
        @endif

        <div class="card space-y-4 p-5 lg:col-span-2">
            <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm">
                <span class="text-slate-500">Enquiry:</span>
                <a href="{{ route('inbox.show', $lead) }}" class="font-medium text-orange-700 hover:underline">{{ $lead->display_name }}</a>
                · {{ $lead->phone }} · {{ $lead->enquiry_type->label() }}
                @if ($lead->preferred_date) · prefers {{ $lead->preferred_date->format('D d M') }}@endif
                @if ($lead->guest_count) · {{ $lead->guest_count }} guests @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="area_id" class="label">Area</label>
                    <select id="area_id" name="area_id" x-model="area" required class="input">
                        <option value="">Select area…</option>
                        @foreach ($areas as $a)
                            <option value="{{ $a->id }}">{{ $a->name }}{{ $a->capacity ? ' (up to '.$a->capacity.')' : '' }}{{ $a->is_active ? '' : ' — inactive' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="package_id" class="label">Package</label>
                    <select id="package_id" name="package_id" x-model="pkg" class="input">
                        <option value="">No package (custom)</option>
                        @foreach ($packages as $p)
                            <option value="{{ $p->id }}">{{ $p->name }} · {{ Format::money($p->price) }} · {{ Format::duration($p->duration_minutes) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="event_date" class="label">Date</label>
                    <input id="event_date" type="date" name="event_date" x-model="date" required @unless ($isEdit) min="{{ today()->toDateString() }}" @endunless class="input">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="start_time" class="label">Start</label>
                        <input id="start_time" type="time" name="start_time" x-model="start" required class="input">
                    </div>
                    <div>
                        <label for="end_time" class="label">End</label>
                        <input id="end_time" type="time" name="end_time" x-model="end" :required="!pkg" class="input">
                    </div>
                </div>
                <div>
                    <label for="guest_count" class="label">Guests</label>
                    <input id="guest_count" type="number" name="guest_count" x-model.number="guests" required min="1" max="2000" class="input">
                    <p x-show="guestWarning" x-text="guestWarning" x-cloak class="mt-1 text-xs text-rose-700"></p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div class="col-span-2">
                        <label for="child_name" class="label">Birthday child <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="child_name" name="child_name" value="{{ old('child_name', $booking->child_name) }}" maxlength="100" class="input">
                    </div>
                    <div>
                        <label for="child_age" class="label">Turning</label>
                        <input id="child_age" type="number" name="child_age" value="{{ old('child_age', $booking->child_age) }}" min="0" max="18" class="input">
                    </div>
                </div>
            </div>

            @if ($addons->isNotEmpty())
                <fieldset>
                    <legend class="label">Add-ons <span class="font-normal text-slate-400">(quantity)</span></legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($addons as $a)
                            <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm" :class="qty[{{ $a->id }}] > 0 && 'border-orange-300 bg-orange-50'">
                                <input type="number" name="addons[{{ $a->id }}]" x-model.number="qty[{{ $a->id }}]" min="0" max="999" class="input w-16 px-2 py-1">
                                <span class="flex-1">{{ $a->name }}{{ $a->is_active ? '' : ' (inactive)' }}</span>
                                <span class="text-slate-500" x-text="money(cfg.addons[{{ $a->id }}])">{{ Format::money($cfg['addons'][$a->id]) }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            <div>
                <label for="special_requests" class="label">Special requests</label>
                <textarea id="special_requests" name="special_requests" rows="3" maxlength="2000" class="input" placeholder="Theme, cake message, allergies…">{{ old('special_requests', $booking->special_requests) }}</textarea>
            </div>
        </div>

        <div class="space-y-4 lg:sticky lg:top-4 lg:self-start">
            <div class="card p-4 text-sm">
                <h2 class="mb-2 font-semibold">Availability</h2>
                <p x-show="!area || !date" class="text-slate-500">Pick an area and a date.</p>
                <p x-show="area && date && loading" x-cloak class="text-slate-500">Checking…</p>
                <template x-if="area && date && !loading">
                    <div>
                        <p x-show="!busy.length" class="text-emerald-700">✓ Free all day.</p>
                        <ul x-show="busy.length" class="space-y-1">
                            <template x-for="b in busy" :key="b.code">
                                <li class="flex justify-between gap-2 rounded px-2 py-1" :class="clashes(b) ? 'bg-rose-50 text-rose-800' : 'bg-slate-50'">
                                    <span><span class="font-medium" x-text="b.time"></span> · <span x-text="b.name || b.code"></span></span>
                                    <span class="text-xs" x-text="b.status"></span>
                                </li>
                            </template>
                        </ul>
                        <p x-show="busy.some(b => clashes(b))" class="mt-2 font-medium text-rose-700">Your time overlaps a booking above.</p>
                    </div>
                </template>
            </div>

            <div class="card p-4 text-sm">
                <h2 class="mb-2 font-semibold">Total</h2>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-slate-600" x-text="cfg.packages[pkg]?.name ?? 'No package'"></dt><dd x-text="money(pkgPrice)"></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Add-ons</dt><dd x-text="money(addonsTotal)"></dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><dt>Total</dt><dd x-text="money(total)"></dd></div>
                </dl>
                <label for="advance_paid" class="label mt-3">Advance received (₹)</label>
                <input id="advance_paid" type="number" name="advance_paid" x-model="advance" min="0" step="0.01" class="input" placeholder="0">
                <p class="mt-1 text-xs text-slate-500">Cash / UPI already taken. Balance is paid at the counter.</p>
                <div class="mt-2 flex justify-between font-semibold text-rose-700"><span>Balance due</span><span x-text="money(balance)"></span></div>
            </div>

            <button class="btn btn-primary w-full">{{ $isEdit ? 'Save changes' : 'Create tentative booking' }}</button>
            @unless ($isEdit)
                <p class="text-xs text-slate-500">The slot is held as <strong>tentative</strong>. Confirm it from the booking page once the customer agrees.</p>
            @endunless
        </div>
    </form>

    <script>
        function bookingForm(cfg) {
            const mins = (t) => { const [h, m] = (t || '').split(':').map(Number); return h * 60 + (m || 0); };
            return {
                cfg, ...cfg.initial, busy: [], loading: false,
                init() {
                    this.$watch('pkg', () => this.autoEnd());
                    this.$watch('start', () => this.autoEnd());
                    this.$watch('area', () => this.check());
                    this.$watch('date', () => this.check());
                    this.check();
                },
                autoEnd() {
                    const p = cfg.packages[this.pkg];
                    if (!p || !this.start) return;
                    const t = mins(this.start) + p.minutes;
                    this.end = t >= 1440 ? '' : String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
                },
                get pkgPrice() { return cfg.packages[this.pkg]?.price ?? 0; },
                get addonsTotal() { return Object.entries(this.qty).reduce((s, [id, q]) => s + (cfg.addons[id] ?? 0) * (Number(q) || 0), 0); },
                get total() { return this.pkgPrice + this.addonsTotal; },
                get balance() { return Math.max(0, this.total - (Number(this.advance) || 0)); },
                get guestWarning() {
                    const cap = cfg.areas[this.area], max = cfg.packages[this.pkg]?.max;
                    if (cap && this.guests > cap) return `This area holds up to ${cap} guests.`;
                    if (max && this.guests > max) return `This package is for up to ${max} guests.`;
                    return '';
                },
                clashes(b) {
                    if (!this.start || !this.end) return false;
                    const [s, e] = b.time.split('–').map(mins);
                    return mins(this.start) < e && mins(this.end) > s;
                },
                money(v) { return '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); },
                async check() {
                    if (!this.area || !this.date) { this.busy = []; return; }
                    this.loading = true;
                    try {
                        const q = new URLSearchParams({ area_id: this.area, date: this.date, ignore: cfg.ignore ?? '' });
                        const r = await fetch(cfg.availabilityUrl + '?' + q, { headers: { Accept: 'application/json' } });
                        this.busy = r.ok ? (await r.json()).busy : [];
                    } catch (e) {
                        this.busy = [];
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
    </script>
</x-layouts.app>
