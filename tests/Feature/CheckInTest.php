<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Organizer;
use App\Models\Ticket;
use App\Models\TicketCheckin;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(Organizer $organizer): Event
    {
        return Event::factory()->create([
            'organizer_id' => $organizer->id,
            'end_date' => now()->addDays(5)->toDateString(),
            'end_time' => '23:00',
            'status' => 'published',
        ]);
    }

    private function makeTicket(Event $event, ?string $status = Ticket::ACTIVE): Ticket
    {
        $user = User::factory()->create(['role' => 'customer']);
        $ticketType = TicketType::factory()->for($event)->create();
        $booking = Booking::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        return Ticket::factory()->create([
            'booking_id' => $booking->id,
            'ticket_type_id' => $ticketType->id,
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => $status,
            'expired_at' => now()->addDays(3),
        ]);
    }

    private function organizerToken(User $user): string
    {
        return JWTAuth::fromUser($user->fresh());
    }

    public function test_valid_ticket_can_be_checked_in(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event);

        $response = $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => Ticket::USED,
        ]);

        $this->assertDatabaseHas('ticket_checkins', [
            'ticket_id' => $ticket->id,
            'event_id' => $event->id,
            'staff_id' => $organizerUser->id,
            'organizer_id' => $organizer->id,
        ]);
    }

    public function test_invalid_qr_token_is_rejected(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $this->makeTicket($event);

        $response = $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => 'does-not-exist-uuid']);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'not_found');
    }

    public function test_used_ticket_is_rejected(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event, Ticket::USED);

        $response = $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Ticket already used.');
    }

    public function test_duplicate_scan_never_succeeds_twice(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event);

        $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token])
            ->assertOk();

        $second = $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token]);

        $second->assertStatus(409)
            ->assertJsonPath('message', 'Ticket already used.');

        $this->assertSame(1, TicketCheckin::where('ticket_id', $ticket->id)->count());
    }

    public function test_cancelled_ticket_is_rejected(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event, Ticket::CANCELLED);

        $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token])
            ->assertStatus(409);
    }

    public function test_customer_cannot_check_in_via_staff_endpoint(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event);

        $customer = User::factory()->create(['role' => 'customer']);
        $token = JWTAuth::fromUser($customer);

        $this->withToken($token)
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token])
            ->assertStatus(403);
    }

    public function test_staff_cannot_check_in_another_organizers_event(): void
    {
        $organizer = Organizer::factory()->create();
        $otherOrganizer = Organizer::factory()->create(['user_id' => User::factory()->create(['role' => 'organizer'])]);

        // The event belongs to $otherOrganizer.
        $event = $this->makeEvent($otherOrganizer);
        $ticket = $this->makeTicket($event);

        // Staff assigned to $organizer.
        $staffUser = User::factory()->create(['role' => 'event_staff']);
        EventStaff::create([
            'user_id' => $staffUser->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->withToken(JWTAuth::fromUser($staffUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token])
            ->assertStatus(403);
    }

    public function test_staff_can_check_in_own_organizers_event(): void
    {
        $organizer = Organizer::factory()->create();
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event);

        $staffUser = User::factory()->create(['role' => 'event_staff']);
        EventStaff::create([
            'user_id' => $staffUser->id,
            'organizer_id' => $organizer->id,
        ]);

        $this->withToken(JWTAuth::fromUser($staffUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ticket_checkins', [
            'ticket_id' => $ticket->id,
            'organizer_id' => $organizer->id,
            'staff_id' => $staffUser->id,
        ]);
    }

    public function test_checkin_history_is_scoped_to_organizer(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event);

        $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in', ['ticket_code' => $ticket->qr_token])
            ->assertOk();

        $this->withToken($this->organizerToken($organizerUser))
            ->getJson('/api/staff/check-in/history')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_ticket_lookup_does_not_mutate(): void
    {
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::factory()->create(['user_id' => $organizerUser->id]);
        $event = $this->makeEvent($organizer);
        $ticket = $this->makeTicket($event);

        $this->withToken($this->organizerToken($organizerUser))
            ->postJson('/api/staff/check-in/lookup', ['ticket_code' => $ticket->qr_token])
            ->assertOk()
            ->assertJsonPath('valid', true);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'status' => Ticket::ACTIVE,
        ]);
    }
}
