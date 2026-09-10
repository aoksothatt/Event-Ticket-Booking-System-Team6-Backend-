<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        $event = Event::factory()->create();

        return [
            'booking_id' => Booking::factory(),
            'booking_item_id' => null,
            'ticket_type_id' => TicketType::factory()->for($event),
            'user_id' => User::factory(),
            'event_id' => $event->id,
            'ticket_code' => 'TKT-'.strtoupper(fake()->unique()->bothify('######')),
            'qr_token' => (string) Str::uuid(),
            'status' => Ticket::ACTIVE,
            'used_at' => null,
            'expired_at' => now()->addMonth(),
        ];
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Ticket::USED,
            'used_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Ticket::CANCELLED,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Ticket::EXPIRED,
            'expired_at' => now()->subDay(),
        ]);
    }
}
