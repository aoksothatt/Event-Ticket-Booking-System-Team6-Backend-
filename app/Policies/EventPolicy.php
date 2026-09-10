<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::ADMIN->value, Role::ORGANIZER->value], true);
    }

    public function view(User $user, Event $event): bool
    {
        if ($user->role === Role::ADMIN->value) {
            return true;
        }

        if ($user->role === Role::ORGANIZER->value) {
            return $event->organizer_id === $user->activeOrganizer()?->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [Role::ADMIN->value, Role::ORGANIZER->value], true);
    }

    public function update(User $user, Event $event): bool
    {
        if ($user->role === Role::ADMIN->value) {
            return true;
        }

        if ($user->role === Role::ORGANIZER->value) {
            return $event->organizer_id === $user->activeOrganizer()?->id;
        }

        return false;
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }

    public function manageTicketTypes(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }

    public function viewAttendance(User $user, Event $event): bool
    {
        return $this->update($user, $event);
    }
}
