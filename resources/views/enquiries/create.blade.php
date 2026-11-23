<x-layouts.app title="New enquiry">
    @php
        $user = auth()->user();
    @endphp
    @if (! $user->isAdmin() && $user->branch)
        <x-slot:actions>
            <a href="{{ route('branches.qr', $user->branch) }}" target="_blank" class="btn btn-secondary">🖨 Walk-in QR poster</a>
        </x-slot:actions>
    @endif

    <form method="POST" action="{{ route('enquiries.store') }}" x-data="{ sending: false }" @submit="sending = true" class="card max-w-3xl space-y-5 p-5">
        @csrf
        <x-flash />

        <div class="grid gap-4 sm:grid-cols-2">
            <fieldset>
                <legend class="label">Source *</legend>
                <div class="flex gap-2">
                    @foreach ($sources as $s)
                        <label class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm has-checked:border-orange-500 has-checked:bg-orange-50">
                            <input type="radio" name="source" value="{{ $s->value }}" required @checked(old('source', 'phone_call') === $s->value) class="text-orange-600">
                            {{ $s->label() }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <div>
                <label for="branch_id" class="label">Outlet *</label>
                @if ($user->isAdmin())
                    <select id="branch_id" name="branch_id" required class="input">
                        <option value="">Select outlet…</option>
                        @foreach ($outlets as $o)
                            <option value="{{ $o->id }}" @selected(old('branch_id') == $o->id)>{{ $o->name }} ({{ $o->city }})</option>
                        @endforeach
                    </select>
                @else
                    <div class="input bg-slate-50 text-slate-600">{{ $user->branch?->name ?? '—' }}</div>
                @endif
            </div>
        </div>

        @include('enquiries._fields')

        <p class="text-xs text-slate-500">
            If this phone number already has an open enquiry at this outlet, the details are added to it instead of creating a duplicate.
            Follow-up is set {{ config('leads.follow_up_default_hours') }} hours from now.
        </p>
        <div class="flex gap-2">
            <button class="btn btn-primary" :disabled="sending">Save enquiry</button>
            <a href="{{ route('inbox') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
