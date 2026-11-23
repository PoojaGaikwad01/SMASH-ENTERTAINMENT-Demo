<?php

namespace App\Services\BookingExport;

use App\Models\Booking;

/** BOOKING_EXPORT_DRIVER=none (default): no external system yet, nothing is exported. */
class NullBookingExport implements BookingExportService
{
    public function push(Booking $booking, string $event): void {}

    public function exportMany(iterable $bookings, string $name): ?string
    {
        return null;
    }
}
