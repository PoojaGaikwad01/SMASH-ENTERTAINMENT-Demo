<?php

namespace App\Services\BookingExport;

use App\Models\Booking;
use App\Support\Csv;
use Illuminate\Support\Facades\Storage;

/**
 * BOOKING_EXPORT_DRIVER=csv: files on the private "local" disk under BOOKING_EXPORT_PATH (default exports/) for the
 * POS team to pick up. push() appends to booking-events-{Y-m-d}.csv (one row per change, file locked while writing);
 * exportMany() writes {name}.csv (overwritten each run).
 */
class CsvBookingExport implements BookingExportService
{
    public function push(Booking $booking, string $event): void
    {
        $booking->loadMissing(BookingCsv::WITH);
        $path = $this->path('booking-events-'.now()->format('Y-m-d').'.csv');
        $new = ! is_file($path);

        $handle = fopen($path, 'a');
        flock($handle, LOCK_EX);
        if ($new) {
            fwrite($handle, Csv::BOM);
            Csv::put($handle, ['Event', 'Event at', ...BookingCsv::headers()]);
        }
        Csv::put($handle, [$event, now()->format('Y-m-d H:i:s'), ...BookingCsv::row($booking)]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function exportMany(iterable $bookings, string $name): ?string
    {
        $path = $this->path($name.'.csv');
        $handle = fopen($path, 'w');
        fwrite($handle, Csv::BOM);
        Csv::put($handle, BookingCsv::headers());
        foreach ($bookings as $booking) {
            Csv::put($handle, BookingCsv::row($booking->loadMissing(BookingCsv::WITH)));
        }
        fclose($handle);

        return $path;
    }

    /** Absolute path on the local disk; creates the folder. */
    private function path(string $file): string
    {
        $dir = trim((string) config('leads.booking_export.path'), '/') ?: 'exports';
        Storage::disk('local')->makeDirectory($dir);

        return Storage::disk('local')->path($dir.'/'.basename($file));
    }
}
