<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'organizer_id' => Organizer::factory(),
            'category_id' => Category::factory(),
            'venue_id' => Venue::factory(),
            'title' => fake()->sentence(3),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->paragraph(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => (clone $start)->modify('+1 day')->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'status' => 'published',
            'is_trending' => false,
        ];
    }
}
