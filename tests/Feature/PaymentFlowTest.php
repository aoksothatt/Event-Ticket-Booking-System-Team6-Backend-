<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Venue;
use App\Services\Bakong\BakongService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    private function makeEventWithTicket(): array
    {
        $user = User::factory()->create(['role' => 'customer']);
        $organizerUser = User::factory()->create(['role' => 'organizer']);
        $organizer = Organizer::create([
            'user_id' => $organizerUser->id,
            'company_name' => 'Test Events Co.',
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
            'title' => 'Test Concert',
            'slug' => 'test-concert-'.rand(1, 999999),
            'description' => 'A test event',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'start_time' => '19:00:00',
            'end_time' => '23:00:00',
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

    public function test_checkout_requires_authentication(): void
    {
        $this->postJson('/api/checkout', [
            'event_id' => 1,
            'ticket_type_id' => 1,
            'quantity' => 1,
        ])->assertStatus(401);
    }

    public function test_checkout_creates_pending_booking_and_payment_and_returns_qr(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $this->mock(BakongService::class)
            ->shouldReceive('generateQr')
            ->once()
            ->andReturn([
                'md5' => 'md5-checkout-1',
                'qr' => '000201010212khqrpayload',
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 2,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.qr_payload', '000201010212khqrpayload')
            ->assertJsonPath('data.payment.provider', 'bakong')
            ->assertJsonPath('data.payment.status', 'pending');

        $booking = Booking::where('user_id', $user->id)->first();
        $this->assertNotNull($booking);
        $this->assertSame('pending', $booking->status);
        $this->assertSame('51.00', (string) $booking->total_amount);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'status' => 'pending',
            'bakong_md5' => 'md5-checkout-1',
        ]);
        $this->assertDatabaseHas('ticket_types', [
            'id' => $ticketType->id,
            'sold_quantity' => 2,
        ]);
    }

    public function test_checkout_rejects_quantity_beyond_available(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();
        $ticketType->update(['quantity' => 5, 'sold_quantity' => 4]);

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'ticket_type_id' => $ticketType->id,
                'quantity' => 5,
            ])->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_verify_marks_payment_paid_booking_paid_and_generates_tickets(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-TEST-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'subtotal' => 25.50,
            'discount' => 0,
            'service_fee' => 0,
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);
        $booking->items()->create([
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'unit_price' => 25.50,
            'subtotal' => 25.50,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'TXN-TEST-001',
            'transaction_id' => 'TXN-TEST-001',
            'bakong_md5' => 'md5-verify-1',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('checkTransaction')
            ->once()
            ->with('md5-verify-1')
            ->andReturn([
                'status' => 'paid',
                'transaction_id' => 'BAKONG-TXN-9001',
                'raw' => ['data' => ['status' => 'COMPLETED']],
            ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
            'bakong_transaction_id' => 'BAKONG-TXN-9001',
        ]);
        $this->assertDatabaseHas('Booking', [
            'id' => $booking->id,
            'status' => 'paid',
        ]);
        $this->assertSame(1, Ticket::where('booking_id', $booking->id)->count());
    }

    public function test_verify_is_idempotent_when_called_twice(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-TEST-002',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'subtotal' => 25.50,
            'discount' => 0,
            'service_fee' => 0,
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);
        $booking->items()->create([
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'unit_price' => 25.50,
            'subtotal' => 25.50,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'TXN-TEST-002',
            'transaction_id' => 'TXN-TEST-002',
            'bakong_md5' => 'md5-verify-2',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
        $booking->update(['status' => 'paid']);

        // The verifier should return early without calling Bakong because the
        // payment is already paid.
        $this->mock(BakongService::class)->shouldReceive('checkTransaction')->never();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count(), 'No new tickets should be generated for an already-paid booking.');
    }

    public function test_expired_payment_verification_returns_expired(): void
    {
        ['user' => $user, 'event' => $event] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-TEST-003',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 10.00,
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'TXN-TEST-003',
            'transaction_id' => 'TXN-TEST-003',
            'bakong_md5' => 'md5-expired-1',
            'currency' => 'USD',
            'amount' => 10.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->subMinutes(1),
        ]);

        $this->mock(BakongService::class)->shouldReceive('checkTransaction')->never();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'expired']);
    }

    public function test_status_endpoint_returns_pending_status(): void
    {
        ['user' => $user, 'event' => $event] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-TEST-004',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 10.00,
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'TXN-TEST-004',
            'transaction_id' => 'TXN-TEST-004',
            'bakong_md5' => 'md5-status-1',
            'currency' => 'USD',
            'amount' => 10.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/payments/'.$payment->id.'/status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.booking_status', 'pending');
    }

    public function test_user_cannot_view_another_users_payment(): void
    {
        ['user' => $user, 'event' => $event] = $this->makeEventWithTicket();
        $other = User::factory()->create(['role' => 'customer']);

        $booking = Booking::create([
            'booking_number' => 'BK-TEST-005',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 10.00,
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'TXN-TEST-005',
            'transaction_id' => 'TXN-TEST-005',
            'bakong_md5' => 'md5-owner-1',
            'currency' => 'USD',
            'amount' => 10.00,
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        $this->withHeaders($this->authHeaders($other))
            ->getJson('/api/payments/'.$payment->id.'/status')
            ->assertStatus(403);
    }

    public function test_webhook_confirms_payment_and_is_idempotent(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-WEBHOOK-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'subtotal' => 25.50,
            'discount' => 0,
            'service_fee' => 0,
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);
        $booking->items()->create([
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'unit_price' => 25.50,
            'subtotal' => 25.50,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'TXN-WEBHOOK-001',
            'transaction_id' => 'TXN-WEBHOOK-001',
            'bakong_md5' => 'md5-webhook-1',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        config(['bakong.webhook_secret' => 'test-webhook-secret']);

        $mocked = $this->mock(BakongService::class);
        $mocked->shouldReceive('checkTransaction')
            ->with('md5-webhook-1')
            ->andReturn([
                'status' => 'paid',
                'transaction_id' => 'BAKONG-TXN-WEB-1',
                'raw' => ['data' => ['status' => 'COMPLETED']],
            ]);

        $makeWebhookCall = function () {
            return $this->postJson('/api/payments/webhook', [
                'md5' => 'md5-webhook-1',
            ], [
                'X-Bakong-Signature' => 'test-webhook-secret',
                'Accept' => 'application/json',
            ]);
        };

        // First delivery: confirm.
        $makeWebhookCall()->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'paid']);
        $this->assertSame(1, Ticket::where('booking_id', $booking->id)->count());

        // Re-delivery (replay): should be idempotent and not generate more tickets.
        $mocked->shouldReceive('checkTransaction')->never();

        $makeWebhookCall()->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Ticket::where('booking_id', $booking->id)->count(), 'Replayed webhook must not generate duplicate tickets.');
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        config(['bakong.webhook_secret' => 'expected-secret']);

        $this->postJson('/api/payments/webhook', ['md5' => 'anything'], [
            'X-Bakong-Signature' => 'wrong-secret',
        ])->assertStatus(403);
    }
}
