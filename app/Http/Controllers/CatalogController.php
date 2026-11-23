<?php

namespace App\Http\Controllers;

use App\Models\Addon;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Package;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Areas / packages / add-ons per outlet, one controller for all three ({type} route param).
 * Route group `can:manage-catalog`: admin (any outlet) or outlet manager (own outlet only — lists, finds and saves are scoped).
 * An item's outlet is fixed after creation (bookings point at it).
 */
class CatalogController extends Controller
{
    /** fields: name => [label, input (text|number|money|textarea), required] */
    public const TYPES = [
        'areas' => ['model' => Area::class, 'one' => 'Area', 'many' => 'Areas', 'fields' => [
            'name' => ['Name', 'text', true],
            'capacity' => ['Capacity (guests, 0 = no limit)', 'number', false],
        ]],
        'packages' => ['model' => Package::class, 'one' => 'Package', 'many' => 'Packages', 'fields' => [
            'name' => ['Name', 'text', true],
            'price' => ['Price (₹, whole package)', 'money', true],
            'duration_minutes' => ['Duration (minutes)', 'number', true],
            'min_guests' => ['Min guests', 'number', true],
            'max_guests' => ['Max guests (blank = no limit)', 'number', false],
            'description' => ['What’s included', 'textarea', false],
        ]],
        'addons' => ['model' => Addon::class, 'one' => 'Add-on', 'many' => 'Add-ons', 'fields' => [
            'name' => ['Name', 'text', true],
            'price' => ['Price (₹ per unit)', 'money', true],
        ]],
    ];

    public function index(Request $request, string $type)
    {
        $user = $request->user();
        $outletId = $user->isAdmin() ? ($request->integer('outlet') ?: null) : $user->branch_id;

        $items = self::TYPES[$type]['model']::query()
            ->with('outlet:id,name')
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->orderBy('outlet_id')->orderByDesc('is_active')->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('catalog.index', ['type' => $type, 'meta' => self::TYPES[$type], 'items' => $items,
            'outlets' => $this->outlets($request), 'outletId' => $outletId]);
    }

    public function create(Request $request, string $type)
    {
        $model = self::TYPES[$type]['model'];

        return $this->form($request, $type, new $model([
            'is_active' => true,
            'outlet_id' => $request->user()->isAdmin() ? ($request->integer('outlet') ?: null) : $request->user()->branch_id,
        ]));
    }

    public function store(Request $request, string $type)
    {
        self::TYPES[$type]['model']::create($this->validated($request, $type));

        return redirect()->route('catalog.index', ['type' => $type])->with('status', self::TYPES[$type]['one'].' added.');
    }

    public function edit(Request $request, string $type, int $id)
    {
        return $this->form($request, $type, $this->find($request, $type, $id));
    }

    public function update(Request $request, string $type, int $id)
    {
        $item = $this->find($request, $type, $id);
        $item->update($this->validated($request, $type, $item));

        return redirect()->route('catalog.index', ['type' => $type])->with('status', self::TYPES[$type]['one'].' updated.');
    }

    public function destroy(Request $request, string $type, int $id)
    {
        $item = $this->find($request, $type, $id);
        $used = match ($type) {
            'areas' => Booking::where('area_id', $id)->exists(),
            'packages' => Booking::where('package_id', $id)->exists(),
            'addons' => DB::table('booking_addons')->where('addon_id', $id)->exists(),
        };
        if ($used) {
            return back()->withErrors(['item' => 'This is used by bookings, so it can’t be deleted. Untick “Active” to hide it instead.']);
        }

        $item->delete();

        return redirect()->route('catalog.index', ['type' => $type])->with('status', self::TYPES[$type]['one'].' deleted.');
    }

    /** Another outlet's item → 404 for outlet managers. */
    private function find(Request $request, string $type, int $id): Model
    {
        return self::TYPES[$type]['model']::visibleTo($request->user())->findOrFail($id);
    }

    private function form(Request $request, string $type, Model $item)
    {
        return view('catalog.form', ['type' => $type, 'meta' => self::TYPES[$type], 'item' => $item, 'outlets' => $this->outlets($request)]);
    }

    private function outlets(Request $request)
    {
        return $request->user()->isAdmin() ? Branch::orderBy('name')->get(['id', 'name']) : collect();
    }

    private function validated(Request $request, string $type, ?Model $item = null): array
    {
        $user = $request->user();
        $outletId = $item?->outlet_id ?? ($user->isAdmin() ? $request->integer('outlet_id') : $user->branch_id);

        $rules = match ($type) {
            'areas' => ['capacity' => ['nullable', 'integer', 'min:0', 'max:5000']],
            'packages' => [
                'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
                'duration_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
                'min_guests' => ['required', 'integer', 'min:1', 'max:2000'],
                'max_guests' => ['nullable', 'integer', 'gte:min_guests', 'max:2000'],
                'description' => ['nullable', 'string', 'max:1000'],
            ],
            'addons' => ['price' => ['required', 'numeric', 'min:0', 'max:9999999']],
        };
        if (! $item && $user->isAdmin()) {
            $rules['outlet_id'] = ['required', 'integer', 'exists:branches,id'];
        }
        $rules['name'] = ['required', 'string', 'max:100', Rule::unique($type, 'name')->where('outlet_id', $outletId)->ignore($item)];

        $data = $request->validate($rules, ['name.unique' => 'This outlet already has one with that name.']);
        if ($type === 'areas') {
            $data['capacity'] = (int) ($data['capacity'] ?? 0);
        }

        return array_merge($data, ['outlet_id' => $outletId, 'is_active' => $request->boolean('is_active')]);
    }
}
