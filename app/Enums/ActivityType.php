<?php

namespace App\Enums;

/** enquiry_activities.type */
enum ActivityType: string
{
    case Call = 'call';
    case Note = 'note';
    case StatusChange = 'status_change';
    case Message = 'message'; // replied outside the app / automatic WhatsApp or email sent
    case Booking = 'booking'; // booking created / updated / confirmed / completed / cancelled

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Note => 'Note',
            self::StatusChange => 'Status',
            self::Message => 'Message',
            self::Booking => 'Booking',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => '📞',
            self::Note => '📝',
            self::StatusChange => '🔄',
            self::Message => '✉️',
            self::Booking => '📅',
        };
    }
}
