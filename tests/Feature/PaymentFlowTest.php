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
use App\Services\Bakong\BakongException;
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
            ->shouldReceive('generateKhqr')
            ->once()
            ->andReturn([
                'md5' => 'md5-checkout-1',
                'khqr' => '000201010212khqrpayload',
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ])
            ->shouldReceive('generateDeeplink')
            ->once()
            ->andReturn('https://bakong.page.link/short');

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
            ->shouldReceive('checkTransactionByMd5')
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
            'status' => 'confirmed',
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
        $this->mock(BakongService::class)->shouldReceive('checkTransactionByMd5')->never();

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

        $this->mock(BakongService::class)->shouldReceive('checkTransactionByMd5')->never();

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
        $mocked->shouldReceive('checkTransactionByMd5')
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
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'confirmed']);
        $this->assertSame(1, Ticket::where('booking_id', $booking->id)->count());

        // Re-delivery (replay): should be idempotent and not generate more tickets.
        $mocked->shouldReceive('checkTransactionByMd5')->never();

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

    public function test_checkout_with_multiple_items_creates_one_booking_one_payment_and_one_qr(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $vip = TicketType::create([
            'event_id' => $event->id,
            'name' => 'VIP',
            'price' => 100.00,
            'quantity' => 50,
            'sold_quantity' => 0,
            'status' => 'active',
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('generateKhqr')
            ->once()
            ->withArgs(fn ($amount) => abs($amount - 151.00) < 0.001)
            ->andReturn([
                'md5' => 'md5-multi-1',
                'khqr' => '000201010212multikhr',
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ])
            ->shouldReceive('generateDeeplink')
            ->once()
            ->andReturn(null);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'items' => [
                    ['ticket_type_id' => $ticketType->id, 'quantity' => 2],
                    ['ticket_type_id' => $vip->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.qr_payload', '000201010212multikhr')
            ->assertJsonPath('data.payment.status', 'pending');

        $bookings = Booking::where('user_id', $user->id)->get();
        $this->assertCount(1, $bookings, 'A multi-item order must create exactly one booking.');
        $this->assertSame('151.00', (string) $bookings->first()->total_amount);

        $this->assertSame(1, Payment::where('booking_id', $bookings->first()->id)->count(), 'A multi-item order must create exactly one payment.');

        $this->assertDatabaseHas('ticket_types', ['id' => $ticketType->id, 'sold_quantity' => 2]);
        $this->assertDatabaseHas('ticket_types', ['id' => $vip->id, 'sold_quantity' => 1]);
    }

    public function test_checkout_retry_reuses_booking_and_creates_new_payment_reference(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-RETRY-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);
        $booking->items()->create([
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'unit_price' => 25.50,
            'subtotal' => 25.50,
        ]);

        $old = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'PAY-20260911-OLD001',
            'transaction_id' => 'PAY-20260911-OLD001',
            'bakong_md5' => 'md5-old',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'expired',
            'payment_status' => 'expired',
            'expires_at' => now()->subMinutes(1),
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('generateKhqr')
            ->once()
            ->andReturn([
                'md5' => 'md5-retry-1',
                'khqr' => '000201010212retrykhr',
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ])
            ->shouldReceive('generateDeeplink')
            ->once()
            ->andReturn('https://bakong.page.link/retry');

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'booking_id' => $booking->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment.id', static fn ($id) => is_int($id) && $id > 0)
            ->assertJsonPath('data.booking.id', $booking->id);

        $newPaymentId = $response->json('data.payment.id');
        $this->assertNotSame($old->id, $newPaymentId, 'Retry must create a new payment record.');
        $this->assertNotSame('PAY-20260911-OLD001', $response->json('data.payment.transaction_reference'));

        $this->assertSame(1, Booking::where('user_id', $user->id)->count(), 'Retry must not create a duplicate booking.');
        $this->assertSame(2, Payment::where('booking_id', $booking->id)->count(), 'Retry must add a new payment alongside the expired one.');
        $this->assertDatabaseHas('payments', ['id' => $old->id, 'status' => 'expired']);
        $this->assertDatabaseHas('payments', ['id' => $newPaymentId, 'status' => 'pending']);
    }

    public function test_checkout_retry_rejects_another_users_booking(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();
        $other = User::factory()->create(['role' => 'customer']);

        $booking = Booking::create([
            'booking_number' => 'BK-RETRY-002',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);

        $this->withHeaders($this->authHeaders($other))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'booking_id' => $booking->id,
            ])->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_checkout_retry_of_confirmed_booking_is_idempotent(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-RETRY-003',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 25.50,
            'status' => 'confirmed',
        ]);
        $paid = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'PAY-20260911-PAID01',
            'transaction_id' => 'PAY-20260911-PAID01',
            'bakong_md5' => 'md5-paid',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'paid',
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->mock(BakongService::class)->shouldReceive('generateKhqr')->never();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'booking_id' => $booking->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.payment.id', $paid->id)
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.qr_payload', '')
            ->assertJsonPath('data.deeplink', null);

        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count(), 'No new payment should be created for an already-paid booking.');
        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count(), 'Retry of a paid booking must never issue duplicate tickets.');
    }

    public function test_checkout_retry_returns_existing_active_payment_until_it_expires(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-RETRY-004',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);
        $active = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'PAY-20260911-ACTIVE1',
            'transaction_id' => 'PAY-20260911-ACTIVE1',
            'bakong_md5' => 'md5-active',
            'qr_payload' => '000201010212activekhr',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->mock(BakongService::class)->shouldReceive('generateKhqr')->never();

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/checkout', [
                'event_id' => $event->id,
                'booking_id' => $booking->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.payment.id', $active->id)
            ->assertJsonPath('data.qr_payload', '000201010212activekhr');

        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    public function test_legacy_payments_store_never_marks_paid_without_verification(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-LEGACY-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 25.50,
            'status' => 'pending',
        ]);
        $booking->items()->create([
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'unit_price' => 25.50,
            'subtotal' => 25.50,
        ]);

        // Even if a customer explicitly asks for a "paid" status, the legacy
        // endpoint must only record a pending payment and must NEVER confirm a
        // booking or issue tickets without Bakong verification.
        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments', [
                'booking_id' => $booking->id,
                'payment_method' => 'bakong_khqr',
                'payment_status' => 'paid',
                'amount' => 1.00, // client-supplied amount must be ignored too
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '25.50');

        $this->assertSame(1, \App\Models\Payments::where('booking_id', $booking->id)->count());
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'pending']);
        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count(), 'No tickets may be issued without Bakong verification.');
    }

    public function test_payments_index_is_scoped_for_customers(): void
    {
        ['user' => $user, 'event' => $event] = $this->makeEventWithTicket();
        $other = User::factory()->create(['role' => 'customer']);

        $booking = Booking::create([
            'booking_number' => 'BK-SCOPE-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 10.00,
            'status' => 'pending',
        ]);
        \App\Models\Payments::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'PAY-SCOPE-001',
            'transaction_id' => 'PAY-SCOPE-001',
            'amount' => 10.00,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_status' => 'pending',
        ]);

        // The owner sees exactly one payment.
        $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Another customer sees none of the owner's payments.
        $this->withHeaders($this->authHeaders($other))
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A Bakong transaction for a DIFFERENT amount than the payment must NEVER
     * confirm the booking or issue tickets.
     */
    public function test_verify_does_not_confirm_on_amount_mismatch(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-AMT-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
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
            'transaction_reference' => 'PAY-AMT-001',
            'transaction_id' => 'PAY-AMT-001',
            'bakong_md5' => 'md5-amt-1',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('checkTransactionByMd5')
            ->once()
            ->with('md5-amt-1')
            ->andReturn([
                'status' => 'paid',
                'transaction_id' => 'BAKONG-TXN-LOW',
                'amount' => '5.00',
                'currency' => 'USD',
                'raw' => ['data' => ['status' => 'COMPLETED']],
            ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed']);
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'pending']);
        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count(), 'No tickets may be issued for a mismatched transaction.');
    }

    /**
     * A 401 from Bakong (invalid/expired credential) must yield a clean,
     * actionable error — never a successful/paid payment.
     */
    public function test_verify_returns_clean_error_on_bakong_401(): void
    {
        ['user' => $user, 'event' => $event] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-401-001',
            'user_id' => $user->id,
            'event_id' => $event->id,
            'booking_date' => now(),
            'total_amount' => 10.00,
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'provider' => 'bakong',
            'transaction_reference' => 'PAY-401-001',
            'transaction_id' => 'PAY-401-001',
            'bakong_md5' => 'md5-401-1',
            'currency' => 'USD',
            'amount' => 10.00,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('checkTransactionByMd5')
            ->once()
            ->with('md5-401-1')
            ->andThrow(new BakongException('Bakong authentication failed. Please configure a valid Bakong API credential.', 401));

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Bakong authentication failed. Please configure a valid Bakong API credential.');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'pending']);
        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count());
    }

    /**
     * When Bakong reports a FAILED transaction the payment must be persisted
     * as failed locally (both status columns) so the admin dashboard and the
     * customer state machine reflect reality instead of staying "pending".
     */
    public function test_verify_persists_failed_when_bakong_reports_failed(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-FAIL-001',
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
            'transaction_reference' => 'PAY-FAIL-001',
            'transaction_id' => 'PAY-FAIL-001',
            'bakong_md5' => 'md5-failed-1',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('checkTransactionByMd5')
            ->once()
            ->with('md5-failed-1')
            ->andReturn([
                'status' => 'failed',
                'transaction_id' => 'BAKONG-TXN-FAILED',
                'raw' => ['data' => ['status' => 'FAILED']],
            ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed', 'payment_status' => 'failed']);
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'pending']);
        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count(), 'No tickets may be issued for a failed transaction.');
    }

    /**
     * When Bakong reports a TIMEOUT transaction the payment must be persisted
     * as expired locally so a later retry can move the booking forward.
     */
    public function test_verify_persists_expired_when_bakong_reports_timeout(): void
    {
        ['user' => $user, 'event' => $event, 'ticketType' => $ticketType] = $this->makeEventWithTicket();

        $booking = Booking::create([
            'booking_number' => 'BK-EXPIRE-001',
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
            'transaction_reference' => 'PAY-EXPIRE-001',
            'transaction_id' => 'PAY-EXPIRE-001',
            'bakong_md5' => 'md5-timeout-1',
            'currency' => 'USD',
            'amount' => 25.50,
            'status' => 'pending',
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->mock(BakongService::class)
            ->shouldReceive('checkTransactionByMd5')
            ->once()
            ->with('md5-timeout-1')
            ->andReturn([
                'status' => 'expired',
                'transaction_id' => 'BAKONG-TXN-TIMEOUT',
                'raw' => ['data' => ['status' => 'TIMEOUT']],
            ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/payments/'.$payment->id.'/verify');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'expired', 'payment_status' => 'expired']);
        $this->assertDatabaseHas('Booking', ['id' => $booking->id, 'status' => 'pending']);
        $this->assertSame(0, Ticket::where('booking_id', $booking->id)->count(), 'No tickets may be issued for an expired transaction.');
    }
}
