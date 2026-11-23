<?php

namespace App\Enums;

/** Enquiry pipeline. NeedsReview is legacy: only produced by agency parsing (leads.agency_parsing). */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case QuoteSent = 'quote_sent';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Lost = 'lost';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::QuoteSent => 'Quote sent',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Lost => 'Lost',
            self::NeedsReview => 'Needs review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'bg-sky-100 text-sky-700',
            self::Contacted => 'bg-violet-100 text-violet-700',
            self::QuoteSent => 'bg-amber-100 text-amber-800',
            self::Confirmed => 'bg-emerald-100 text-emerald-700',
            self::Completed => 'bg-teal-100 text-teal-700',
            self::Lost => 'bg-slate-100 text-slate-500',
            self::NeedsReview => 'bg-orange-100 text-orange-800',
        };
    }

    /** Still being worked on (not won/finished/lost). */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::QuoteSent, self::NeedsReview], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen())));
    }

    /** Statuses shown in filters / reports (NeedsReview only while agency parsing is on). */
    public static function options(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s !== self::NeedsReview || config('leads.agency_parsing')));
    }
}
