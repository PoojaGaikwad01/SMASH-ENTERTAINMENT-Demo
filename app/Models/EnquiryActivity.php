<?php

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Timeline entry on an enquiry (call / note / status change / message). user_id null = system. Create via Lead::logActivity(). */
#[Fillable(['lead_id', 'user_id', 'type', 'body', 'created_at'])]
class EnquiryActivity extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => ActivityType::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Activity counts as enquiry activity for the inbox sort (same as a new message).
        static::created(fn (EnquiryActivity $a) => Lead::whereKey($a->lead_id)->update(['last_activity_at' => $a->created_at]));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
