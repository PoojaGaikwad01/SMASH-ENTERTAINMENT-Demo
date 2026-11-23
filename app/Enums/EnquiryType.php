<?php

namespace App\Enums;

enum EnquiryType: string
{
    case General = 'general';
    case Tickets = 'tickets';
    case Birthday = 'birthday';
    case SchoolGroup = 'school_group';
    case Corporate = 'corporate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Tickets => 'Entry tickets',
            self::Birthday => 'Birthday party',
            self::SchoolGroup => 'School group',
            self::Corporate => 'Corporate event',
            self::Other => 'Other',
        };
    }

    /** Best guess from free text (e.g. a Lead Ad answer "Kids birthday party"). Null when nothing matches. */
    public static function guess(?string $text): ?self
    {
        $t = mb_strtolower(trim((string) $text));
        if ($t === '') {
            return null;
        }

        return self::tryFrom(str_replace([' ', '-'], '_', $t)) ?? match (true) {
            str_contains($t, 'birthday') || str_contains($t, 'party') => self::Birthday,
            str_contains($t, 'school') || str_contains($t, 'student') => self::SchoolGroup,
            str_contains($t, 'corporate') || str_contains($t, 'office') || str_contains($t, 'team') => self::Corporate,
            str_contains($t, 'ticket') || str_contains($t, 'entry') || str_contains($t, 'pass') => self::Tickets,
            default => null,
        };
    }
}
