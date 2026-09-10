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
                'address' => 'Sangkat Chaktomuk, Khan Daun Penh',
                'city' => 'Phnom Penh',
                'province' => null,
                'country' => 'Cambodia',
                'capacity' => 50000,
                'description' => 'The national sports stadium of Cambodia.',
                'status' => 'active',
            ]
        );
    }
}