@php
    use App\Http\Controllers\CatalogController;
    use App\Support\Format;
    $isAdmin = auth()->user()->isAdmin();
@endphp
<x-layouts.app title="Catalogue">
    <x-slot:actions>
        <a href="{{ route('catalog.create', ['type' => $type, 'outlet' => $outletId]) }}" class="btn btn-primary">+ New {{ strtolower($meta['one']) }}</a>
    </x-slot:actions>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" aria-label="Catalogue sections">
            @foreach (CatalogController::TYPES as $key => $t)
                <a href="{{ route('catalog.index', ['type' => $key, 'outlet' => $outletId]) }}"
                   @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white shadow-sm text-slate-900' => $key === $type, 'text-slate-600 hover:text-slate-900' => $key !== $type])>{{ $t['many'] }}</a>
            @endforeach
        </nav>
        @if ($isAdmin)
            <form method="GET" class="flex items-center gap-2">
                <label for="outlet" class="text-sm text-slate-500">Outlet</label>
                <select id="outlet" name="outlet" onchange="this.form.submit()" class="input w-auto py-1.5">
                    <option value="">All outlets</option>
                    @foreach ($outlets as $o)
                        <option value="{{ $o->id }}" @selected($outletId === $o->id)>{{ $o->name }}</option>
                    @endforeach
                </select>
                <noscript><button class="btn btn-secondary py-1.5">Go</button></noscript>
            </form>
        @endif
    </div>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ $meta['one'] }}</th>
                    @if ($isAdmin)<th>Outlet</th>@endif
                    <th>{{ match ($type) { 'areas' => 'Capacity', 'packages' => 'Price · duration · guests', default => 'Price' } }}</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr @class(['text-slate-400' => ! $item->is_active])>
                        <td>
                            <div class="font-medium">{{ $item->name }}</div>
                            @if ($type === 'packages' && $item->description)
                                <div class="max-w-md truncate text-xs text-slate-500">{{ $item->description }}</div>
                            @endif
                        </td>
                        @if ($isAdmin)<td class="text-slate-600">{{ $item->outlet?->name }}</td>@endif
                        <td class="whitespace-nowrap">
                            @switch($type)
                                @case('areas')
                                    {{ $item->capacity ? $item->capacity.' guests' : 'No limit' }}
                                    @break
                                @case('packages')
                                    {{ Format::money($item->price) }} · {{ Format::duration($item->duration_minutes) }} ·
                                    {{ $item->min_guests }}{{ $item->max_guests ? '–'.$item->max_guests : '+' }} guests
                                    @break
                                @default
                                    {{ Format::money($item->price) }}
                            @endswitch
                        </td>
                        <td>
                            <span @class(['badge', 'bg-emerald-100 text-emerald-700' => $item->is_active, 'bg-slate-100 text-slate-500' => ! $item->is_active])>
                                {{ $item->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="text-right">
                            <a href="{{ route('catalog.edit', ['type' => $type, 'id' => $item->id]) }}" class="font-medium text-orange-700 hover:underline">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-8 text-center text-slate-500">No {{ strtolower($meta['many']) }} yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
</x-layouts.app>
