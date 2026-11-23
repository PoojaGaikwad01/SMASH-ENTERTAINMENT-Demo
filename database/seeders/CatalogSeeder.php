<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Package;
use Illuminate\Database\Seeder;

/** Demo areas / packages / addons for every outlet. Idempotent (matched by outlet + name). Prices in INR. */
class CatalogSeeder extends Seeder
{
    private const AREAS = [
        ['name' => 'Party Room A', 'capacity' => 30],
        ['name' => 'Party Room B', 'capacity' => 20],
        ['name' => 'Event Arena', 'capacity' => 100],
    ];

    private const PACKAGES = [
        ['name' => 'Birthday Basic', 'price' => 14999, 'duration_minutes' => 120, 'min_guests' => 10, 'max_guests' => 20,
            'description' => '2 hrs party room, unlimited soft play, 1 arcade card (₹200) per child, snacks + juice.'],
        ['name' => 'Birthday Premium', 'price' => 24999, 'duration_minutes' => 180, 'min_guests' => 15, 'max_guests' => 30,
            'description' => '3 hrs party room, bowling + arcade, host and games, meal for kids and parents.'],
        ['name' => 'School Group Outing', 'price' => 19999, 'duration_minutes' => 180, 'min_guests' => 20, 'max_guests' => 60,
            'description' => 'Weekday group entry, supervised activities, snack box per student.'],
        ['name' => 'Corporate Fun Evening', 'price' => 39999, 'duration_minutes' => 180, 'min_guests' => 20, 'max_guests' => 80,
            'description' => 'Exclusive arena slot, bowling + VR tournament, buffet dinner.'],
    ];

    private const ADDONS = [
        ['name' => 'Theme decoration', 'price' => 3500],
        ['name' => 'Custom cake (2 kg)', 'price' => 2800],
        ['name' => 'Return gifts (pack of 10)', 'price' => 2500],
        ['name' => 'Photographer (2 hrs)', 'price' => 4000],
        ['name' => 'Extra pizza platter', 'price' => 1200],
    ];

    public function run(): void
    {
        foreach (Branch::all() as $outlet) {
            foreach (self::AREAS as $a) {
                Area::firstOrCreate(['outlet_id' => $outlet->id, 'name' => $a['name']], $a + ['is_active' => true]);
            }
            foreach (self::PACKAGES as $p) {
                Package::firstOrCreate(['outlet_id' => $outlet->id, 'name' => $p['name']], $p + ['is_active' => true]);
            }
            foreach (self::ADDONS as $a) {
                Addon::firstOrCreate(['outlet_id' => $outlet->id, 'name' => $a['name']], $a + ['is_active' => true]);
            }
        }
    }
}
