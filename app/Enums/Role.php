<?php

namespace App\Enums;

enum Role: string
{
    case CUSTOMER = 'customer';
    case ORGANIZER = 'organizer';
    case EVENT_STAFF = 'event_staff';
    case ADMIN = 'admin';

    /**
     * All defined roles.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
