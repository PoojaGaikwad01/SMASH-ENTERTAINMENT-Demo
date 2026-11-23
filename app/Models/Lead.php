<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\EnquiryType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'source', 'external_id', 'contact_id', 'name', 'phone', 'email', 'city',
    'branch_id', 'assigned_by', 'assigned_at', 'replied_at', 'status',
    'campaign_name', 'ad_name', 'form_name', 'agency_name', 'raw_payload', 'last_activity_at',
    'enquiry_type', 'preferred_date', 'guest_count', 'notes', 'lost_reason', 'next_follow_up_at', 'created_by_user_id',
])]
class Lead extends Model
{
    use BelongsToBranch, HasFactory;

    /** A lead is an enquiry (UI label). */
    protected $attributes = ['status' => 'new', 'enquiry_type' => 'general'];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'enquiry_type' => EnquiryType::class,
            'preferred_date' => 'date',
            'guest_count' => 'integer',
            'next_follow_up_at' => 'datetime',
            'raw_payload' => 'array',
            'assigned_at' => 'datetime',
            'replied_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Message channels where the customer talks to us directly (excludes 'lead_ad', 'agency', 'note'). */
    public const CHAT_CHANNELS = ['whatsapp', 'messenger', 'instagram'];

    /**
     * Who/where a free-text reply goes, and whether the 24h customer-service window is open.
     * Based on the customer's latest inbound chat message — never on agency forwards or lead-ad summaries.
     *
     * @return array{channel: ?string, recipient: ?string, open: bool, closes_at: ?\Carbon\CarbonInterface}
     */
    public function replyWindow(): array
    {
        $last = ($this->relationLoaded('messages') ? $this->messages : $this->messages()->get())
            ->filter(fn (Message $m) => $m->isInbound() && in_array($m->channel, self::CHAT_CHANNELS, true))
            ->last();

        $channel = $last?->channel;
        $recipient = match ($channel) {
            'whatsapp' => $this->phone ? ltrim($this->phone, '+') : null,
            'messenger', 'instagram' => $this->contact_id,
            default => null,
        };
        $closesAt = $last?->created_at->copy()->addHours(24);

        return [
            'channel' => $channel,
            'recipient' => $recipient,
            'open' => $recipient !== null && $closesAt !== null && $closesAt->isFuture(),
            'closes_at' => $closesAt,
        ];
    }

    /** Assign or reassign to an outlet; the new outlet owes a fresh reply. Status is unchanged. */
    public function assignTo(Branch $branch, User $by): void
    {
        $this->forceFill([
            'branch_id' => $branch->id,
            'assigned_by' => $by->id,
            'assigned_at' => now(),
            'replied_at' => null,
        ])->save();
    }

    /** new → contacted (later stages are kept); replied_at keeps the FIRST reply since assignment. */
    public function markReplied(): void
    {
        $this->forceFill([
            'status' => $this->status === LeadStatus::New ? LeadStatus::Contacted : $this->status,
            'replied_at' => $this->replied_at ?? now(),
        ])->save();
    }

    /** Waiting for the outlet's first response (drives reminders). Phone-call / walk-in enquiries were already spoken to. */
    public function awaitingReply(): bool
    {
        return $this->status === LeadStatus::New && $this->branch_id !== null && $this->replied_at === null
            && ! $this->source->isStaffEntered();
    }

    /**
     * Move the enquiry through the pipeline. Lost needs a reason; lost/completed clear the follow-up;
     * contacted records the first reply. The status_change activity is logged by the `updated` hook below.
     */
    public function changeStatus(LeadStatus $to, ?string $lostReason = null): void
    {
        $this->status = $to;
        $this->lost_reason = $to === LeadStatus::Lost ? $lostReason : null;
        if (in_array($to, [LeadStatus::Lost, LeadStatus::Completed], true)) {
            $this->next_follow_up_at = null;
        }
        if ($to === LeadStatus::Contacted) {
            $this->replied_at ??= now();
        }
        $this->save();
    }

    /** Add a timeline entry. $by defaults to the signed-in user (null = system, e.g. webhooks/queue). */
    public function logActivity(ActivityType $type, ?string $body = null, ?User $by = null): EnquiryActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'body' => $body,
            'user_id' => $by?->id ?? auth()->id(),
            'created_at' => now(),
        ]);
    }

    protected static function booted(): void
    {
        static::creating(fn (Lead $lead) => $lead->last_activity_at ??= $lead->created_at ?? now());

        // Every status change, from anywhere (buttons, replies, review fixes, webhooks reopening), is logged.
        static::updated(function (Lead $lead) {
            if (! $lead->wasChanged('status')) {
                return;
            }
            $from = LeadStatus::tryFrom((string) $lead->getRawOriginal('status'));
            $body = ($from?->label() ?? '—').' → '.$lead->status->label()
                .($lead->status === LeadStatus::Lost && $lead->lost_reason ? " · Reason: {$lead->lost_reason}" : '');
            $lead->logActivity(ActivityType::StatusChange, $body);
        });
    }

    // --- Relations ---

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('created_at');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(EnquiryActivity::class)->orderBy('created_at')->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->orderByDesc('event_date')->orderByDesc('start_time');
    }

    // --- Attributes ---

    /** Stored normalized (+91…) when recognisable, otherwise as typed so admin can fix it. */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => Phone::normalize($v) ?? (filled($v) ? trim($v) : null));
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->name ?: ($this->phone ?: 'Unknown '.$this->source->short().' lead'));
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->name) {
                return '#';
            }

            return collect(preg_split('/\s+/', trim($this->name)))->take(2)
                ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
        });
    }

    // --- Scopes ---

    /** Follow-ups due by the end of today (incl. overdue), for enquiries still being worked on. */
    public function scopeFollowUpsDue(Builder $query): void
    {
        $query->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now()->endOfDay())
            ->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Completed->value]);
    }

    /** Inbox filters: q (name/phone), source, status, enquiry_type, branch_id ("none" = unassigned), from/to (received date, Y-m-d). */
    public function scopeFilter(Builder $query, array $f): void
    {
        // Blank -> no filter (Carbon::parse(null) would mean "now"); unparseable -> ignored.
        $date = fn ($v) => is_string($v) && trim($v) !== '' ? rescue(fn () => Carbon::parse($v), null, false) : null;

        $query
            ->when($f['enquiry_type'] ?? null, fn ($q, $v) => $q->where('enquiry_type', $v))
            ->when($date($f['from'] ?? null), fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($date($f['to'] ?? null), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['branch_id'] ?? null, fn ($q, $v) => $v === 'none' ? $q->whereNull('branch_id') : $q->where('branch_id', (int) $v))
            ->when(trim($f['q'] ?? ''), function ($q, $term) {
                $digits = ltrim(preg_replace('/\D/', '', $term), '0');
                // whereLike = case-insensitive on every driver (ILIKE on PostgreSQL).
                $q->where(fn ($w) => $w->whereLike('name', "%{$term}%")
                    ->when(strlen($digits) >= 3, fn ($w) => $w->orWhereLike('phone', "%{$digits}%")));
            });
    }
}
