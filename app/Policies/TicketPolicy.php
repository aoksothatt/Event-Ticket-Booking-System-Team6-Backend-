<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if (in_array($user->role, [Role::ADMIN->value, Role::EVENT_STAFF->value], true)) {
            return true;
        }

        if ($user->role === Role::CUSTOMER->value) {
            return $ticket->user_id === $user->id;
        }

        if ($user->role === Role::ORGANIZER->value) {
            return $ticket->event?->organizer_id === $user->activeOrganizer()?->id;
        }

        return false;
    }

    public function checkIn(User $user, Ticket $ticket): bool
    {
        // Admin, organizer, and event_staff all may perform check-ins.
        if (in_array($user->role, [Role::ADMIN->value, Role::ORGANIZER->value, Role::EVENT_STAFF->value], true)) {
            // Organizer / staff must work within their own organizer's events.
            if (in_array($user->role, [Role::ORGANIZER->value, Role::EVENT_STAFF->value], true)) {
                $organizerId = $user->activeOrganizer()?->id;

                return $organizerId !== null && $ticket->event?->organizer_id === $organizerId;
            }

            return true;
        }

        return false;
    }
}
