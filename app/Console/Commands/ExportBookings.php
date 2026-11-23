<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Branch;
use App\Services\BookingExport\BookingCsv;
use App\Services\BookingExport\BookingExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Day list for the POS (scheduled daily 06:00 for today). Confirmed + completed bookings via BOOKING_EXPORT_DRIVER. */
class ExportBookings extends Command
{
    protected $signature = 'bookings:export {--date= : Event date (Y-m-d), default today} {--outlet= : Outlet slug (default all)}';

    protected $description = 'Export a day\'s confirmed bookings through the booking export driver (none | csv)';

    public function handle(BookingExportService $export): int
    {
        $date = rescue(fn () => Carbon::parse($this->option('date') ?: 'today'), null, false);
        if (! $date) {
            $this->error('Invalid --date.');

            return self::INVALID;
        }
        $outlet = $this->option('outlet') ? Branch::where('slug', $this->option('outlet'))->first() : null;
        if ($this->option('outlet') && ! $outlet) {
            $this->error('Unknown outlet slug.');

            return self::INVALID;
        }

        $bookings = Booking::whereDate('event_date', $date->toDateString())
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->when($outlet, fn ($q) => $q->where('outlet_id', $outlet->id))
            ->with(BookingCsv::WITH)->orderBy('outlet_id')->orderBy('start_time')->get();

        $where = $export->exportMany($bookings, 'bookings-'.$date->format('Y-m-d').($outlet ? '-'.$outlet->slug : ''));
        $this->info($where
            ? "Exported {$bookings->count()} booking(s) to {$where}"
            : "{$bookings->count()} booking(s) found; BOOKING_EXPORT_DRIVER=".config('leads.booking_export.driver').' exports nothing.');

        return self::SUCCESS;
    }
}
