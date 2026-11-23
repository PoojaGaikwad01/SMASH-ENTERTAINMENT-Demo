<?php

namespace App\Services\BookingExport;

use App\Models\Booking;

/**
 * Integration point for the billing POS / Semnox (not connected yet). Bound in AppServiceProvider from
 * BOOKING_EXPORT_DRIVER: none (NullBookingExport) | csv (CsvBookingExport). A future API driver implements this
 * interface (and should queue its HTTP calls). Callers go through BookingService::pushExport(), which never lets an
 * export failure break the booking flow.
 */
interface BookingExportService
{
    /** One booking change: created | updated | confirmed | completed | cancelled | payment. */
    public function push(Booking $booking, string $event): void;

    /**
     * A batch (e.g. one day's bookings for the POS). Returns where it went (file path / remote id), or null if nothing was exported.
     *
     * @param  iterable<Booking>  $bookings
     */
    public function exportMany(iterable $bookings, string $name): ?string;
}
