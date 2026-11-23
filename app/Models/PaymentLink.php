<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Razorpay Payment Link for a booking advance. Written only by App\Services\PaymentService. */
#[Fillable([
    'booking_id', 'provider_link_id', 'reference_id', 'short_url', 'amount', 'amount_paid', 'status',
    'provider_payment_id', 'created_by_user_id', 'expires_at', 'paid_at',
])]
class PaymentLink extends Model
{
    public const CREATED = 'created';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $attributes = ['status' => self::CREATED, 'amount_paid' => 0];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Created and not past its expiry: the customer can still pay it. */
    public function isOpen(): bool
    {
        return $this->status === self::CREATED && ! $this->expires_at?->isPast();
    }

    public function displayStatus(): string
    {
        return $this->status === self::CREATED && $this->expires_at?->isPast() ? self::EXPIRED : $this->status;
    }

    public function statusColor(): string
    {
        return match ($this->displayStatus()) {
            self::PAID => 'bg-emerald-100 text-emerald-800',
            self::CREATED => 'bg-amber-100 text-amber-800',
            default => 'bg-slate-100 text-slate-600',
        };
    }
}
