<?php

namespace App\Enums;

/** bookings.status: tentative → confirmed → completed, or cancelled from either open state. */
enum BookingStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Tentative => 'bg-amber-100 text-amber-800',
            self::Confirmed => 'bg-emerald-100 text-emerald-800',
            self::Completed => 'bg-slate-200 text-slate-700',
            self::Cancelled => 'bg-rose-100 text-rose-700',
        };
    }

    /** Tentative and confirmed bookings hold their slot (double-booking check, calendar) and can still be edited. */
    public function holdsSlot(): bool
    {
        return $this === self::Tentative || $this === self::Confirmed;
    }

    /** @return list<string> */
    public static function slotHoldingValues(): array
    {
        return [self::Tentative->value, self::Confirmed->value];
    }

    /** @return list<self> allowed next statuses */
    public function next(): array
    {
        return match ($this) {
            self::Tentative => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            default => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
