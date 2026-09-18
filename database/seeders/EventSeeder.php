<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * PostgreSQL test data for the Vue homepage:
 * a Football category, one organizer, and a mix of upcoming / trending /
 * past events with ticket types and gallery images.
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        $football = Category::firstOrCreate(
            ['name' => 'Football'],
            [
                'description' => 'Football matches and tournaments.',
                'status' => 'active',
            ]
        );

        $sports = Category::firstOrCreate(['name' => 'Sports']);

        $organizerUser = User::firstOrCreate(
            ['email' => 'organizer@example.com'],
            [
                'name' => 'Demo Organizer',
                'password' => bcrypt('password'),
                'role' => 'organizer',
            ]
        );

        $organizer = Organizer::firstOrCreate(
            ['user_id' => $organizerUser->id],
            [
                'company_name' => 'Cambodia Sports Promotions',
                'company_logo' => null,
                'phone' => '+855 12 345 678',
                'website' => 'https://example.com',
                'description' => 'Organizing the best football and sports events in Cambodia.',
                'is_verified' => true,
            ]
        );

        $venue = Venue::firstOrCreate(
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

        $events = [
            [
                'title' => 'Cambodia vs Thailand Friendly',
                'category' => $football,
                'description' => 'A friendly international football match between Cambodia and Thailand.',
                'start_date' => today()->addDays(7)->toDateString(),
                'end_date' => today()->addDays(7)->toDateString(),
                'start_time' => '18:30:00',
                'end_time' => '21:00:00',
                'status' => 'published',
                'is_trending' => true,
                'ticket_types' => [
                    ['name' => 'Standard', 'price' => 10.00, 'quantity' => 2000, 'description' => 'East stand seating'],
                    ['name' => 'VIP', 'price' => 50.00, 'quantity' => 300, 'description' => 'VIP box with refreshments'],
                ],
            ],
            [
                'title' => 'National Football League Finals',
                'category' => $football,
                'description' => 'The championship decider of the national football league.',
                'start_date' => today()->addDays(14)->toDateString(),
                'end_date' => today()->addDays(14)->toDateString(),
                'start_time' => '16:00:00',
                'end_time' => '18:30:00',
                'status' => 'published',
                'is_trending' => true,
                'ticket_types' => [
                    ['name' => 'Standard', 'price' => 12.00, 'quantity' => 3000, 'description' => 'General admission'],
                    ['name' => 'Family', 'price' => 30.00, 'quantity' => 500, 'description' => 'Family package for 4'],
                ],
            ],
            [
                'title' => 'Phnom Penh Half Marathon',
                'category' => $sports,
                'description' => 'Annual half marathon through the city with cash prizes for top finishers.',
                'start_date' => today()->addDays(21)->toDateString(),
                'end_date' => today()->addDays(21)->toDateString(),
                'start_time' => '06:00:00',
                'end_time' => '10:00:00',
                'status' => 'published',
                'is_trending' => false,
                'ticket_types' => [
                    ['name' => 'Runners Entry', 'price' => 15.00, 'quantity' => 1500, 'description' => 'Race bib + t-shirt'],
                ],
            ],
            [
                'title' => 'Charity Gala Dinner 2026',
                'category' => Category::firstOrCreate(['name' => 'Music']),
                'description' => 'An evening of live music and fundraising for children hospitals.',
                'start_date' => today()->subDays(10)->toDateString(),
                'end_date' => today()->subDays(10)->toDateString(),
                'start_time' => '19:00:00',
                'end_time' => '23:00:00',
                'status' => 'published',
                'is_trending' => false,
                'ticket_types' => [
                    ['name' => 'Standard', 'price' => 25.00, 'quantity' => 500, 'description' => 'Dinner seating'],
                ],
            ],
        ];

        foreach ($events as $data) {
            $ticketTypes = $data['ticket_types'];
            $category = $data['category'];

            unset($data['ticket_types'], $data['category']);

            $slug = Str::slug($data['title']) . '-' . Str::lower(Str::random(6));

            $event = Event::firstOrCreate(
                ['slug' => $slug],
                array_merge($data, [
                    'organizer_id' => $organizer->id,
                    'category_id' => $category->id,
                    'venue_id' => $venue->id,
                    'banner' => 'events/football-banner.jpg',
                ])
            );

            if ($event->wasRecentlyCreated) {
                $event->images()->create([
                    'image' => 'events/football-1.jpg',
                    'sort_order' => 1,
                ]);

                $event->images()->create([
                    'image' => 'events/football-2.jpg',
                    'sort_order' => 2,
                ]);

                foreach ($ticketTypes as $ticketType) {
                    $event->ticketTypes()->create(array_merge($ticketType, [
                        'status' => 'active',
                        'sold_quantity' => 0,
                    ]));
                }
            }
        }
    }
}