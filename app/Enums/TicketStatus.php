<?php

namespace App\Enums;

enum TicketStatus: string
{
    case DONE = 'DONE';
    case ACTIVE = 'ACTIVE';
    case USED = 'USED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';
}
