<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Branch;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Availability calendar (day / week per outlet), counter lookup and the booking form's availability check. */
class BookingCalendarController extends Controller
{
    /** Day-view grid hours; widened automatically when a booking falls outside. */
    private const DAY_START = 9;

    private const DAY_END = 23;

    public function __construct(private BookingService $bookings) {}

    public function calendar(Request $request)
    {
        $user = $request->user();
        $outlets = $user->isAdmin() ? Branch::active()->orderBy('name')->get(['id', 'name']) : collect();
        $outlet = $user->isAdmin()
            ? ($outlets->firstWhere('id', $request->integer('outlet')) ?? $outlets->first())
            : $user->branch;
        abort_unless($outlet, 404, 'No outlet to show.');

        $view = $request->query('view') === 'week' ? 'week' : 'day';
        $date = rescue(fn () => Carbon::parse((string) $request->query('date', 'today'))->startOfDay(), today(), false);
        [$from, $to] = $view === 'week' ? [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()->startOfDay()] : [$date, $date];

        $bookings = Booking::where('outlet_id', $outlet->id)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->whereDate('event_date', '>=', $from->toDateString())
            ->whereDate('event_date', '<=', $to->toDateString())
            ->with(['lead:id,name,phone', 'package:id,name'])
            ->orderBy('start_time')
            ->get();
        $areas = Area::where('outlet_id', $outlet->id)->orderBy('name')->get()
            ->filter(fn (Area $a) => $a->is_active || $bookings->contains('area_id', $a->id))->values();

        $hours = [
            min(self::DAY_START, (int) $bookings->min(fn ($b) => (int) substr($b->start_time, 0, 2)) ?: self::DAY_START),
            max(self::DAY_END, (int) $bookings->max(fn ($b) => (int) ceil(Carbon::parse($b->end_time)->secondsSinceMidnight() / 3600))),
        ];

        return view('bookings.calendar', compact('outlets', 'outlet', 'view', 'date', 'from', 'to', 'bookings', 'areas', 'hours'));
    }

    /** Counter: find a booking by code or phone (also name). Today's first, then upcoming, then past. */
    public function lookup(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $results = $q === '' ? collect() : Booking::visibleTo($request->user())
            ->search($q)
            ->with(['lead:id,name,phone,email', 'area:id,name', 'package:id,name', 'addons', 'outlet:id,name'])
            ->latest('event_date')->limit(30)->get()
            ->sortBy(fn (Booking $b) => [
                $b->event_date->isToday() ? 0 : ($b->event_date->isFuture() ? 1 : 2),
                $b->event_date->isPast() ? -$b->event_date->getTimestamp() : $b->event_date->getTimestamp(),
                $b->start_time,
            ])->values();

        return view('bookings.lookup', compact('q', 'results'));
    }

    /** JSON for the booking form: slots already held in an area on a date. */
    public function availability(Request $request)
    {
        $data = $request->validate([
            'area_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'ignore' => ['nullable', 'integer'],
        ]);
        $area = Area::visibleTo($request->user())->findOrFail($data['area_id']);
        $busy = $this->bookings->clashes($area->id, $data['date'], '00:00:00', '23:59:59', $data['ignore'] ?? null)->load('lead:id,name');

        return response()->json([
            'area' => $area->name,
            'capacity' => $area->capacity,
            'busy' => $busy->map(fn (Booking $b) => [
                'code' => $b->booking_code,
                'time' => $b->time_range,
                'status' => $b->status->label(),
                'name' => $b->lead?->name,
            ])->values(),
        ]);
    }
}
