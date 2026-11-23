<x-layouts.app :title="($item->exists ? 'Edit ' : 'New ').strtolower($meta['one'])">
    <div class="grid max-w-4xl gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ $item->exists ? route('catalog.update', ['type' => $type, 'id' => $item->id]) : route('catalog.store', ['type' => $type]) }}" class="card space-y-4 p-5">
            @csrf
            @if ($item->exists) @method('PUT') @endif

            <div>
                <label for="outlet_id" class="label">Outlet</label>
                @if (! $item->exists && auth()->user()->isAdmin())
                    <select id="outlet_id" name="outlet_id" required class="input">
                        <option value="">Select outlet…</option>
                        @foreach ($outlets as $o)
                            <option value="{{ $o->id }}" @selected((int) old('outlet_id', $item->outlet_id) === $o->id)>{{ $o->name }}</option>
                        @endforeach
                    </select>
                @else
                    <p class="text-sm text-slate-700">{{ $item->outlet?->name ?? auth()->user()->branch?->name }}</p>
                @endif
            </div>

            @foreach ($meta['fields'] as $name => [$label, $input, $required])
                <div>
                    <label for="{{ $name }}" class="label">{{ $label }}</label>
                    @if ($input === 'textarea')
                        <textarea id="{{ $name }}" name="{{ $name }}" rows="3" maxlength="1000" class="input" @required($required)>{{ old($name, $item->$name) }}</textarea>
                    @else
                        <input id="{{ $name }}" name="{{ $name }}" value="{{ old($name, $item->$name) }}" @required($required)
                               @if ($input === 'text') maxlength="100" @else type="number" min="0" step="{{ $input === 'money' ? '0.01' : '1' }}" inputmode="decimal" @endif
                               class="input">
                    @endif
                </div>
            @endforeach

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active)) class="rounded border-slate-300 text-orange-600">
                Active <span class="text-slate-400">(inactive items can’t be picked for new bookings)</span>
            </label>
            <div class="flex gap-2">
                <button class="btn btn-primary">Save</button>
                <a href="{{ route('catalog.index', ['type' => $type]) }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>

        @if ($item->exists)
            <form method="POST" action="{{ route('catalog.destroy', ['type' => $type, 'id' => $item->id]) }}" onsubmit="return confirm('Delete this {{ strtolower($meta['one']) }}?')" class="card flex h-fit items-center justify-between gap-3 p-5">
                @csrf @method('DELETE')
                <p class="text-sm text-slate-600">Delete only if no booking uses it. Otherwise untick Active.</p>
                <button class="btn btn-danger shrink-0">Delete</button>
            </form>
        @endif
    </div>
</x-layouts.app>
