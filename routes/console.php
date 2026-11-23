<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Needs a cron entry: * * * * * php artisan schedule:run   (locally: php artisan schedule:work; Docker: docker/start.sh)
Schedule::command('leads:send-reminders')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('bookings:send-notifications')->everyFifteenMinutes()->withoutOverlapping(); // booking reminder + thank-you
Schedule::command('bookings:export')->dailyAt('06:00'); // today's confirmed bookings for the POS (no-op unless BOOKING_EXPORT_DRIVER=csv)

// PostgreSQL: app tables live in their own schema (DB_SCHEMA). Safe to run on every deploy.
Artisan::command('db:ensure-schema', function () {
    if (DB::getDriverName() !== 'pgsql') {
        return;
    }
    $schema = str_replace('"', '', (string) config('database.connections.pgsql.search_path'));
    DB::statement("create schema if not exists \"{$schema}\"");
    $this->info("Schema \"{$schema}\" is ready.");
})->purpose('Create the PostgreSQL schema (DB_SCHEMA) if it does not exist');
