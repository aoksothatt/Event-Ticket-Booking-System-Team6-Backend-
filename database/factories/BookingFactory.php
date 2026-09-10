<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'booking_number' => 'BK-'.strtoupper(fake()->unique()->bothify('##########')),
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'booking_date' => now(),
            'total_amount' => fake()->randomFloat(2, 10, 500),
            'status' => 'confirmed',
        ];
    }
}
