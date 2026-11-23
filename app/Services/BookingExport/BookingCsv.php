<?php

namespace App\Services\BookingExport;

use App\Models\Booking;

/** One CSV layout for bookings: the report download and the CSV export driver share it. booking_code is the key for the POS. */
class BookingCsv
{
    /** Relations row() needs — eager load them for batches. */
    public const WITH = ['lead:id,name,phone,email,source,enquiry_type', 'outlet:id,name', 'area:id,name', 'package:id,name', 'addons', 'createdBy:id,name'];

    public static function headers(): array
    {
        return ['Booking code', 'Status', 'Payment', 'Outlet', 'Area', 'Package', 'Event date', 'Start', 'End', 'Guests',
            'Customer', 'Phone', 'Email', 'Enquiry source', 'Enquiry type', 'Birthday child', 'Child age', 'Add-ons',
            'Package price', 'Add-ons total', 'Total', 'Advance paid', 'Balance due', 'Special requests',
            'Created at', 'Created by', 'Confirmed at', 'Cancel reason'];
    }

    public static function row(Booking $b): array
    {
        $addonsTotal = $b->addons->sum(fn ($a) => $a->pivot->qty * (float) $a->pivot->price);

        return [
            $b->booking_code, $b->status->label(), $b->payment_status->label(), $b->outlet?->name, $b->area?->name,
            $b->package?->name ?? 'Custom', $b->event_date->toDateString(), substr($b->start_time, 0, 5), substr($b->end_time, 0, 5),
            $b->guest_count, $b->lead?->name, $b->lead?->phone, $b->lead?->email, $b->lead?->source->label(),
            $b->lead?->enquiry_type->label(), $b->child_name, $b->child_age,
            $b->addons->map(fn ($a) => "{$a->name} x{$a->pivot->qty}")->join('; '),
            self::amount($b->package_price), self::amount($addonsTotal), self::amount($b->total_amount),
            self::amount($b->advance_paid), self::amount($b->balance_due), $b->special_requests,
            $b->created_at->format('Y-m-d H:i'), $b->createdBy?->name, $b->confirmed_at?->format('Y-m-d H:i'), $b->cancel_reason,
        ];
    }

    /** Plain number for spreadsheets / POS import (no ₹, no grouping). */
    private static function amount(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
