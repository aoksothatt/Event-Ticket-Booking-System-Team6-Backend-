<?php

namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    public function run(): void
    {
        Venue::firstOrCreate(
            ['name' => 'National Stadium'],
            [
                'location' => 'Phnom Penh',
                'capacity' => 50000,
            ]
        );
    }
}