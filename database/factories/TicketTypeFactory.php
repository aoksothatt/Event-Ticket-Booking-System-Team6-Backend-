<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->randomElement(['General Admission', 'VIP', 'Early Bird', 'Student']),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 5, 200),
            'quantity' => 100,
            'sold_quantity' => 0,
            'status' => 'active',
        ];
    }
}
