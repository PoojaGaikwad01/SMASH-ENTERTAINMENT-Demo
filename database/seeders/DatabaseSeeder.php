<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Idempotent: re-running never overwrites existing rows or changed passwords. Default password for all seeded users: "password". */
    public function run(): void
    {
        $outlets = collect([
            ['name' => 'Smash Andheri', 'slug' => 'andheri', 'city' => 'Mumbai'],
            ['name' => 'Smash Pune', 'slug' => 'pune', 'city' => 'Pune'],
        ])->map(fn ($o) => Branch::firstOrCreate(['slug' => $o['slug']], $o + ['is_active' => true]));

        // One user per role. Outlet staff belong to the first outlet.
        $users = [
            ['email' => 'admin@smash.test', 'name' => 'Admin', 'role' => Role::Admin, 'branch_id' => null],
            ['email' => 'manager@smash.test', 'name' => 'Rahul Mehta', 'role' => Role::OutletManager, 'branch_id' => $outlets[0]->id],
            ['email' => 'frontdesk@smash.test', 'name' => 'Sneha Patil', 'role' => Role::FrontDesk, 'branch_id' => $outlets[0]->id],
        ];
        foreach ($users as $i => $u) {
            User::firstOrCreate(['email' => $u['email']], $u + [
                'phone' => '+91980000000'.$i,
                'password' => 'password',
                'is_active' => true,
            ]);
        }

        $this->call(CatalogSeeder::class);

        if (config('leads.mode') === 'mock') {
            $this->call([LeadSeeder::class, BookingSeeder::class]);
        }
    }
}
