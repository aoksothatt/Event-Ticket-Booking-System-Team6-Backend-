<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Event;
use App\Models\Ticket;
use App\Policies\BookingPolicy;
use App\Policies\EventPolicy;
use App\Policies\TicketPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected array $policies = [
        Event::class => EventPolicy::class,
        Booking::class => BookingPolicy::class,
        Ticket::class => TicketPolicy::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Convenience gate: a user may manage an event if the EventPolicy says so.
        Gate::define('manage-event', fn ($user, Event $event) => $user->can('update', $event));
        Gate::define('check-in-ticket', fn ($user, Ticket $ticket) => $user->can('checkIn', $ticket));
    }
}
