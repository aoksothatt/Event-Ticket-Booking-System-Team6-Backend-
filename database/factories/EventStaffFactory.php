<?php

namespace Database\Factories;

use App\Models\EventStaff;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventStaff>
 */
class EventStaffFactory extends Factory
{
    protected $model = EventStaff::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organizer_id' => Organizer::factory(),
            'is_active' => true,
        ];
    }
}
