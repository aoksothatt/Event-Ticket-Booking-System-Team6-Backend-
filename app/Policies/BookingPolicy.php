<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        if ($user->role === Role::ADMIN->value) {
            return true;
        }

        if ($user->role === Role::CUSTOMER->value) {
            return $booking->user_id === $user->id;
        }

        if ($user->role === Role::ORGANIZER->value) {
            $organizerId = $user->activeOrganizer()?->id;

            return $organizerId !== null
                && $booking->event?->organizer_id === $organizerId;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === Role::CUSTOMER->value;
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        if ($user->role === Role::CUSTOMER->value) {
            return $booking->user_id === $user->id;
        }

        return $this->view($user, $booking);
    }
}
