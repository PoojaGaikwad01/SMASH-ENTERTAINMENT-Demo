<?php

namespace App\Enums;

/** bookings.payment_status, derived from advance_paid vs total_amount. */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Partial => 'Advance paid',
            self::Paid => 'Fully paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'bg-slate-100 text-slate-600',
            self::Partial => 'bg-sky-100 text-sky-800',
            self::Paid => 'bg-emerald-100 text-emerald-800',
        };
    }

    public static function fromAmounts(float $total, float $paid): self
    {
        return match (true) {
            $paid <= 0 => self::Unpaid,
            $paid >= $total => self::Paid,
            default => self::Partial,
        };
    }
}
