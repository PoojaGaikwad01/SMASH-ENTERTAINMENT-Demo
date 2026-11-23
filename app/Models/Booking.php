<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOutlet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A slot in an area of an outlet, always created from an enquiry (lead). Create/update/status changes go through
 * App\Services\BookingService (double-booking check, totals, enquiry side effects). Times are local (app timezone),
 * stored as H:i:s; event_date is a DATE — query it with whereDate().
 */
#[Fillable([
    'lead_id', 'outlet_id', 'area_id', 'package_id', 'package_price', 'event_date', 'start_time', 'end_time',
    'guest_count', 'child_name', 'child_age', 'special_requests', 'total_amount', 'advance_paid', 'balance_due',
    'payment_status', 'status', 'created_by_user_id', 'confirmed_at', 'cancel_reason',
])]
class Booking extends Model
{
    use BelongsToOutlet;

    protected $attributes = ['status' => 'tentative', 'payment_status' => 'unpaid'];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'guest_count' => 'integer',
            'child_age' => 'integer',
            'package_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'advance_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'status' => BookingStatus::class,
            'payment_status' => PaymentStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Code = SMB-{created yymmdd}-{id}: unique without a counter table. Placeholder until the id exists.
        static::creating(fn (Booking $b) => $b->booking_code ??= 'TMP-'.Str::upper(Str::random(12)));
        static::created(fn (Booking $b) => $b->forceFill([
            'booking_code' => sprintf('SMB-%s-%04d', $b->created_at->format('ymd'), $b->id),
        ])->saveQuietly());
    }

    // --- Relations ---

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Pivot booking_addons: qty + unit price at booking time. */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'booking_addons')->withPivot('qty', 'price')->orderBy('addons.name');
    }

    /** Customer messages (confirmation / reminder / thank-you), newest first. */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class)->latest('created_at')->latest('id');
    }

    /** Razorpay advance links (Phase 6), newest first. */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class)->latest('id');
    }

    /** The link the customer can still pay (newest open one), if any. */
    public function openPaymentLink(): ?PaymentLink
    {
        return ($this->relationLoaded('paymentLinks') ? $this->paymentLinks : $this->paymentLinks()->get())
            ->first(fn (PaymentLink $l) => $l->isOpen());
    }

    /** Default amount for a new payment link: PAYMENT_ADVANCE_PERCENT of the total minus what's paid; the full balance once that's covered. */
    public function suggestedAdvance(): float
    {
        $target = ceil((float) $this->total_amount * config('leads.payments.advance_percent') / 100) - (float) $this->advance_paid;

        return round($target > 0 ? min($target, (float) $this->balance_due) : (float) $this->balance_due, 2);
    }

    // --- Helpers ---

    public function startsAt(): Carbon
    {
        return $this->event_date->copy()->setTimeFromTimeString($this->start_time);
    }

    public function endsAt(): Carbon
    {
        return $this->event_date->copy()->setTimeFromTimeString($this->end_time);
    }

    /** "16:00–18:00" */
    protected function timeRange(): Attribute
    {
        return Attribute::get(fn () => substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5));
    }

    // --- Scopes ---

    public function scopeHoldingSlot(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::slotHoldingValues());
    }

    /** Booking code, customer name or phone (any 4+ digits; last 10 used so +91 / 0 prefixes match). */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);
        if ($term === '') {
            return;
        }
        $digits = substr(preg_replace('/\D/', '', $term), -10);

        $query->where(fn ($w) => $w->whereLike('booking_code', "%{$term}%")
            ->orWhereHas('lead', fn ($l) => $l->where(fn ($x) => $x->whereLike('name', "%{$term}%")
                ->when(strlen($digits) >= 4, fn ($x) => $x->orWhereLike('phone', "%{$digits}%")))));
    }
}
