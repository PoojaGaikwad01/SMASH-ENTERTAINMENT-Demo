<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
use App\Models\Addon;
use App\Models\Area;
use App\Models\Booking;
use App\Models\Lead;
use App\Models\Package;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/** Mock only: demo bookings around today for contacted / quote-sent enquiries. Goes through BookingService so enquiries move to Confirmed / Completed. */
class BookingSeeder extends Seeder
{
    /** [days from today, start, final status]; leads alternate between outlets, so each outlet gets every status. */
    private const PLAN = [
        [0, '11:00', 'confirmed'], [0, '12:00', 'confirmed'], [1, '12:00', 'tentative'], [1, '16:00', 'tentative'],
        [-2, '15:00', 'completed'], [-1, '12:00', 'completed'], [3, '11:00', 'cancelled'], [5, '18:00', 'confirmed'],
    ];

    public function run(BookingService $service): void
    {
        if (Booking::exists()) {
            return;
        }

        // Open enquiries, alternating between outlets so every outlet's staff see demo bookings.
        $byOutlet = Lead::whereNotNull('branch_id')
            ->whereIn('status', [LeadStatus::QuoteSent->value, LeadStatus::Contacted->value, LeadStatus::New->value])
            ->orderByRaw("CASE status WHEN 'quote_sent' THEN 0 WHEN 'contacted' THEN 1 ELSE 2 END")->orderBy('id')
            ->get()->groupBy('branch_id')->values();
        $leads = collect(range(0, $byOutlet->map->count()->max() - 1))
            ->flatMap(fn ($i) => $byOutlet->map(fn ($group) => $group->get($i))->filter())
            ->take(count(self::PLAN));

        foreach ($leads->values() as $i => $lead) {
            [$days, $start, $status] = self::PLAN[$i];
            $packages = Package::where('outlet_id', $lead->branch_id)->orderBy('id')->get();
            $package = $packages[$i % max(1, $packages->count())] ?? null;
            $guests = $package ? $package->min_guests + 2 : 15;
            $area = Area::where('outlet_id', $lead->branch_id)->where('capacity', '>=', $guests)->orderBy('capacity')->first();
            if (! $package || ! $area) {
                continue;
            }
            $addons = Addon::where('outlet_id', $lead->branch_id)->orderBy('id')->take(1 + $i % 3)->pluck('id');
            $by = User::where('branch_id', $lead->branch_id)->orderBy('id')->first();

            try {
                $booking = $service->save($lead, [
                    'area_id' => $area->id,
                    'package_id' => $package->id,
                    'event_date' => today()->addDays($days)->toDateString(),
                    'start_time' => $start,
                    'guest_count' => $guests,
                    'child_name' => str_contains(strtolower($package->name), 'birthday') ? strtok($lead->name, ' ').' Jr' : null,
                    'child_age' => str_contains(strtolower($package->name), 'birthday') ? 5 + $i : null,
                    'addons' => $addons->mapWithKeys(fn ($id) => [$id => 1])->all(),
                    'advance_paid' => $status === 'tentative' ? 0 : 5000,
                ], null, $by);
            } catch (ValidationException) {
                continue; // slot already taken in this outlet
            }

            match ($status) {
                'confirmed' => $service->transition($booking, BookingStatus::Confirmed, null, $by),
                'completed' => [$service->transition($booking, BookingStatus::Confirmed, null, $by), $service->transition($booking->refresh(), BookingStatus::Completed, null, $by)],
                'cancelled' => $service->transition($booking, BookingStatus::Cancelled, 'Customer postponed to next month', $by),
                default => null,
            };
        }
    }
}
