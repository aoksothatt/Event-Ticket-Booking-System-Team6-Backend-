<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\EventStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EventStatsService — single source of truth for the admin event detail
 * numbers. Only bookings whose status is `confirmed` or `paid` count as a
 * sale; capacity is the summed ticket-type quantity; revenue is the sum of
 * the order-item subtotals of successful bookings.
 */
class EventStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(): array
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::create([
            'user_id' => $organizerUser->id,
            'company_name' => 'Stats Co.',
            'is_verified' => true,
        ]);
        $category = Category::create(['name' => 'Music']);
        $venue = Venue::create([
            'name' => 'National Stadium',
            'address' => '123 Main St',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'capacity' => 500,
        ]);
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'category_id' => $category->id,
            'venue_id' => $venue->id,
            'title' => 'Stats Concert',
            'slug' => 'stats-concert-'.rand(1, 999999),
            'description' => 'A test event',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'start_time' => '19:00:00',
            'end_time' => '23:00:00',
            'status' => 'published',
        ]);

        $vip = TicketType::create([
            'event_id' => $event->id,
            'name' => 'VIP',
            'price' => 25.50,
            'quantity' => 50,
            'sold_quantity' => 0,
            'status' => 'active',
        ]);
        $ga = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price' => 15.00,
            'quantity' => 100,
            'sold_quantity' => 0,
            'status' => 'active',
        ]);

        return compact('event', 'vip', 'ga');
    }

    private function makeBooking(User $user, Event $event, string $status): Booking
    {
        return Booking::create([
            'booking_number' => 'BK-STATS-'.rand(100000, 999999),
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 0,
            'status' => $status,
        ]);
    }

    public function test_counts_only_confirmed_and_paid_bookings(): void
    {
        ['event' => $event, 'vip' => $vip, 'ga' => $ga] = $this->makeEvent();
        $buyer = User::factory()->create(['role' => 'customer']);

        $confirmed = $this->makeBooking($buyer, $event, 'confirmed');
        BookingItem::create([
            'booking_id' => $confirmed->id,
            'ticket_type_id' => $vip->id,
            'quantity' => 2,
            'unit_price' => 25.50,
            'subtotal' => 51.00,
        ]);
        BookingItem::create([
            'booking_id' => $confirmed->id,
            'ticket_type_id' => $ga->id,
            'quantity' => 3,
            'unit_price' => 15.00,
            'subtotal' => 45.00,
        ]);

        $paid = $this->makeBooking($buyer, $event, 'paid');
        BookingItem::create([
            'booking_id' => $paid->id,
            'ticket_type_id' => $ga->id,
            'quantity' => 1,
            'unit_price' => 15.00,
            'subtotal' => 15.00,
        ]);

        // These must NOT count towards sales or revenue.
        foreach (['pending', 'failed', 'cancelled'] as $status) {
            $booking = $this->makeBooking($buyer, $event, $status);
            BookingItem::create([
                'booking_id' => $booking->id,
                'ticket_type_id' => $ga->id,
                'quantity' => 10,
                'unit_price' => 15.00,
                'subtotal' => 150.00,
            ]);
        }

        $stats = app(EventStatsService::class)->forEvent($event->id);

        $this->assertSame(150, $stats['capacity']);
        $this->assertSame(6, $stats['tickets_sold']);
        $this->assertEqualsWithDelta(111.00, $stats['revenue'], 0.001);
    }

    public function test_returns_zeroes_for_event_without_sales(): void
    {
        ['event' => $event] = $this->makeEvent();

        $stats = app(EventStatsService::class)->forEvent($event->id);

        $this->assertSame(150, $stats['capacity']);
        $this->assertSame(0, $stats['tickets_sold']);
        $this->assertSame(0.0, $stats['revenue']);
    }
}