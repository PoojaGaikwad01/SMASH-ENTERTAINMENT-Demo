<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to send a booking message on one channel. Rows are written BEFORE the job is queued, so any row for
 * (booking, type, channel) means "already attempted" and the scheduler never sends it again (Resend creates a new row).
 */
#[Fillable(['booking_id', 'channel', 'type', 'status', 'provider_response', 'created_at'])]
class NotificationLog extends Model
{
    public const UPDATED_AT = null;

    public const CHANNELS = ['whatsapp', 'email'];

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const MOCKED = 'mocked'; // APP_MODE=mock: logged instead of sent

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped'; // no phone / email / template, or booking cancelled

    protected function casts(): array
    {
        return ['type' => NotificationType::class, 'created_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::SENT => 'bg-emerald-100 text-emerald-800',
            self::MOCKED => 'bg-sky-100 text-sky-800',
            self::FAILED => 'bg-rose-100 text-rose-700',
            self::QUEUED => 'bg-amber-100 text-amber-800',
            default => 'bg-slate-100 text-slate-600',
        };
    }

    /** "WhatsApp" / "Email" (table); $inline: "WhatsApp" / "email" (inside a sentence). */
    public function channelLabel(bool $inline = false): string
    {
        return $this->channel === 'whatsapp' ? 'WhatsApp' : ($inline ? 'email' : 'Email');
    }
}
