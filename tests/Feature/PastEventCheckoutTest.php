<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\Bakong\BakongService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * "Auto handle past events" booking rule.
 *
 * Once the current datetime has passed an event's end datetime
 * (end_date + end_time) the checkout must refuse both NEW bookings and
 * payment retries, unless `event.auto_handle_past` is disabled — the escape
 * hatch for administrators who want to keep an event buyable past its end.
 */
class PastEventCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function setAutoHandlePast(bool $enabled): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'event.auto_handle_past'],
            ['key' => 'event.auto_handle_past', 'value' => $enabled ? '1' : '0', 'type' => 'boolean', 'group' => 'event']
        );
    }

    private function tokenFor(User $user): string
    {
        return JWTAuth::fromUser($user->fresh());
    }

    private function makePastEventWithTicket(): array
    {
        $user = User::factory()->create(['role' => 'customer']);
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::create([
            'user_id' => $organizerUser->id,
            'company_name' => 'Past Event Co.',
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
            'title' => 'Ended Festival',
            'slug' => 'ended-festival-'.rand(1, 999999),
            'description' => 'A test event',
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '20:00:00',
            'status' => 'published',
        ]);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price' => 25.50,
            'quantity' => 100,
            'sold_quantity' => 0,
            'status' => 'active',
        ]);

        return compact('user', 'event', 'ticketType');
    }

    private function mockBakong(): void
    {
        $this->mock(BakongService::class)
            ->shouldReceive('generateKhqr')
            ->andReturn([
                'md5' => 'md5-past-1',
                'khqr' => '000201010212pastkhr',
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ])
            ->shouldReceive('generateDeeplink')
            ->andReturn(null);
    }

    public function test_new_booking_rejected_once_event_has_ended(): void
    {
        $this->setAutoHandlePast(true);
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makePastEventWithTicket();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', 'This event has ended and tickets are no longer available for purchase.');
    }

    public function test_payment_retry_rejected_once_event_has_ended(): void
    {
        $this->setAutoHandlePast(true);
        ['user' => $user, 'event' => $event] = $this->makePastEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-PAST-'.rand(1000, 9999),
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 51.00,
            'status' => 'pending',
        ]);

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'booking_id' => $booking->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This event has ended and tickets are no longer available for purchase.');
    }

    public function test_checkout_allowed_for_ended_event_when_auto_handle_disabled(): void
    {
        $this->setAutoHandlePast(false);
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makePastEventWithTicket();
        $this->mockBakong();

        $this->withToken($this->tokenFor($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 1,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }
}