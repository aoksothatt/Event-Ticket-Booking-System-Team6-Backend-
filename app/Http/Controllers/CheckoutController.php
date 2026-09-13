<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Payment;
use App\Models\TicketType;
use App\Repositories\PaymentRepository;
use App\Services\Bakong\BakongException;
use App\Services\Bakong\BakongService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CheckoutController
 *
 * Orchestrates the complete purchase flow:
 *   validate request
 *     -> verify availability
 *     -> calculate total (authoritative, from DB)
 *     -> create Booking (pending)
 *     -> create Payment (pending)
 *     -> generate KHQR locally (PHP KHQR SDK)
 *     -> compute MD5(KHQR)
 *     -> persist QR payload / MD5 / expiration
 *     -> commit transaction
 *     -> generate optional wallet deeplink (best-effort)
 *     -> return booking + payment + QR + deeplink to the client.
 *
 * A single checkout can carry multiple ticket types; they are consolidated
 * into ONE booking, ONE payment and ONE KHQR covering the whole order so the
 * customer only ever scans a single QR.
 *
 * Passing an existing "booking_id" performs a RETRY: a brand-new payment (new
 * reference + new KHQR) is created for the same pending booking instead of
 * creating a duplicate booking or incrementing inventory again.
 *
 * The controller never talks to Bakong directly; all gateway calls go
 * through BakongService and all persistence through PaymentRepository.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly BakongService $bakongService,
        private readonly PaymentRepository $payments
    ) {}

    public function store(CreatePaymentRequest $request)
    {
        $user = $request->user();

        // Idempotency guard: prevent double-click / duplicate submit by
        // holding a short-lived lock keyed on the user + event.
        $lockKey = 'checkout:' . $user->id . ':' . $request->input('event_id');
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'A checkout is already in progress. Please wait.',
            ], 429);
        }

        try {
            return $this->processCheckout($request);
        } finally {
            $lock->release();
        }
    }

    protected function processCheckout(CreatePaymentRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        $bookingId = isset($validated['booking_id']) ? (int) $validated['booking_id'] : null;
        $items = $bookingId === null ? $request->items() : [];

        if ($bookingId === null && $items === []) {
            return response()->json([
                'success' => false,
                'message' => 'Please select at least one ticket.',
            ], 422);
        }

        $useExistingBooking = $bookingId !== null;
        $skipDeeplink = false;

        try {
            $result = DB::transaction(function () use ($validated, $user, $items, $useExistingBooking, $bookingId, &$skipDeeplink) {
                // Verify the event exists and is bookable.
                $event = Event::find($validated['event_id']);
                if (! $event) {
                    throw new \RuntimeException('This event no longer exists.', 404);
                }
                if ($event->status !== 'published') {
                    throw new \RuntimeException(
                        $event->status === 'cancelled'
                            ? 'This event has been cancelled and tickets are no longer available.'
                            : 'This event is not currently available for booking.'
                    );
                }

                if ($useExistingBooking) {
                    return $this->createRetryPayment($bookingId, $event, $user, $skipDeeplink);
                }

                return $this->createNewBooking($event, $items, $user);
            });
        } catch (BakongException $e) {
            Log::channel('bakong')->error('Checkout aborted due to Bakong error.', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getHttpStatus());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->getResponse();
        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
            Log::channel('bakong')->error('Checkout aborted due to runtime error.', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('Checkout aborted due to unexpected error.', [
                'user_id' => $user->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred during checkout. Please try again.',
            ], 500);
        }

        // Optional wallet deeplink — generated AFTER commit so a transient
        // deeplink failure can never roll the booking back. The KHQR payment
        // remains fully usable if this is skipped.
        $deeplink = null;
        if (! $skipDeeplink) {
            $deeplink = $this->bakongService->generateDeeplink($result['qr']['khqr'], [
                'booking_number' => $result['booking']->booking_number,
            ]);

            if (is_string($deeplink) && $deeplink !== '') {
                $result['payment']->update(['deeplink' => $deeplink]);
            }
        }

        $payment = $result['payment']->fresh('booking');

        Log::channel('bakong')->info('Checkout completed.', [
            'booking_id' => $result['booking']->id,
            'payment_id' => $payment->id,
            'has_deeplink' => is_string($deeplink) && $deeplink !== '',
        ]);

        return response()->json([
            'success' => true,
            'message' => $useExistingBooking
                ? 'A new payment was created for your booking. Scan the KHQR to pay.'
                : 'Checkout initiated. Scan the KHQR to pay.',
            'data' => [
                'booking' => $result['booking']->load('event', 'items.ticketType'),
                'payment' => new PaymentResource($payment),
                'qr_payload' => $result['qr']['khqr'],
                'md5' => $result['qr']['md5'],
                'deeplink' => $payment->deeplink ?? $deeplink,
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                // The browser uses this to ask the backend to verify a paid
                // KHQR. Keep the wait short enough that a completed payment
                // closes the QR and opens the issued tickets promptly.
                'poll_interval_seconds' => 15,
            ],
        ], 201);
    }

    /**
     * Create a brand-new booking, reserve inventory and issue one payment.
     *
     * @param  array<int, array{ticket_type_id: int, quantity: int}>  $items
     * @return array{booking: Booking, payment: Payment, qr: array{khqr: string, md5: string, expires_at: string}}
     */
    protected function createNewBooking(Event $event, array $items, $user): array
    {
        $subtotalAmounts = [];

        foreach ($items as $item) {
            // Lock each ticket row to prevent overselling under concurrency.
            $ticketType = TicketType::lockForUpdate()->find($item['ticket_type_id']);

            if (! $ticketType) {
                throw new \RuntimeException('This ticket type is no longer available.');
            }

            if ($ticketType->event_id !== (int) $event->id) {
                throw new \RuntimeException('The selected ticket does not belong to this event.');
            }

            if ($ticketType->status !== 'active') {
                throw new \RuntimeException('This ticket type is not currently on sale.');
            }

            $available = (int) $ticketType->quantity - (int) $ticketType->sold_quantity;
            if ($item['quantity'] > $available) {
                if ($available === 0) {
                    throw new \RuntimeException("Sorry, {$ticketType->name} is sold out.");
                }
                throw new \RuntimeException(
                    "Only {$available} {$ticketType->name} ticket(s) remaining. You requested {$item['quantity']}."
                );
            }

            $subtotal = (float) $ticketType->price * (int) $item['quantity'];
            $subtotalAmounts[$ticketType->id] = [
                'subtotal' => $subtotal,
                'unit_price' => (float) $ticketType->price,
                'quantity' => (int) $item['quantity'],
            ];
        }

        // Authoritative pricing — never trust a client-supplied amount.
        $totalAmount = array_sum(array_column($subtotalAmounts, 'subtotal'));

        $booking = Booking::create([
            'booking_number' => 'BK-' . strtoupper(Str::random(10)),
            'user_id' => $user->id,
            'event_id' => (int) $event->id,
            'booking_date' => now(),
            'discount' => 0.0,
            'service_fee' => 0.0,
            'total_amount' => $totalAmount,
            'status' => 'pending',
        ]);

        foreach ($subtotalAmounts as $ticketTypeId => $line) {
            // Reserve inventory.
            TicketType::whereKey($ticketTypeId)->increment('sold_quantity', $line['quantity']);

            $booking->items()->create([
                'ticket_type_id' => $ticketTypeId,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'subtotal' => $line['subtotal'],
            ]);
        }

        $payment = $this->createPaymentForBooking($booking, $totalAmount);

        return [
            'booking' => $booking,
            'payment' => $payment,
            'qr' => [
                'khqr' => $payment->qr_payload,
                'md5' => $payment->bakong_md5,
                'expires_at' => $payment->expires_at?->toIso8601String() ?? now()->addMinutes((int) config('bakong.qr_expiration_minutes', 15))->toIso8601String(),
            ],
        ];
    }

    /**
     * Create a fresh payment + KHQR for an existing pending booking.
     *
     * Used when a customer retries an expired/failed payment. The booking is
     * NOT duplicated and inventory is NOT touched again. If a previous
     * payment is still active, that same QR is returned to keep things
     * idempotent.
     *
     * @return array{booking: Booking, payment: Payment, qr: array{khqr: string, md5: string, expires_at: string}}
     */
    protected function createRetryPayment(int $bookingId, Event $event, $user, bool &$skipDeeplink): array
    {
        $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

        if (! $booking) {
            throw new \RuntimeException('This booking no longer exists.', 404);
        }

        if ((int) $booking->user_id !== (int) $user->id) {
            throw new \RuntimeException('You can only pay for your own bookings.', 403);
        }

        if ((int) $booking->event_id !== (int) $event->id) {
            throw new \RuntimeException('This booking does not belong to the selected event.', 422);
        }

        if (in_array($booking->status, ['cancelled', 'rejected', 'failed'], true)) {
            throw new \RuntimeException('This booking can no longer be paid for.', 422);
        }

        if (in_array($booking->status, ['paid', 'confirmed'], true)) {
            // Already paid — return the existing paid payment (idempotent)
            // WITHOUT a reusable QR. The client must treat this as a completed
            // payment: no new payment, no new QR, no duplicate tickets.
            $paid = $booking->paymentRecords()->where('status', Payment::STATUS_PAID)->latest('id')->first();
            if ($paid) {
                $skipDeeplink = true;

                return [
                    'booking' => $booking,
                    'payment' => $paid,
                    'qr' => [
                        'khqr' => '',
                        'md5' => '',
                        'expires_at' => '',
                    ],
                ];
            }

            throw new \RuntimeException('This booking has already been paid.', 409);
        }

        // An earlier payment attempt may still be running — return its QR so
        // the customer does not end up with two live QRs for the same booking.
        $active = $booking->paymentRecords()
            ->where('status', Payment::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($active && ! $active->isExpired() && $active->qr_payload) {
            $skipDeeplink = true;

            return [
                'booking' => $booking,
                'payment' => $active,
                'qr' => [
                    'khqr' => $active->qr_payload,
                    'md5' => $active->bakong_md5,
                    'expires_at' => $active->expires_at?->toIso8601String() ?? '',
                ],
            ];
        }

        if ($active) {
            $this->payments->markExpired($active);
        }

        $payment = $this->createPaymentForBooking($booking, (float) $booking->total_amount);

        return [
            'booking' => $booking,
            'payment' => $payment,
            'qr' => [
                'khqr' => $payment->qr_payload,
                'md5' => $payment->bakong_md5,
                'expires_at' => $payment->expires_at?->toIso8601String() ?? '',
            ],
        ];
    }

    /**
     * Build a pending Payment + KHQR for a booking. Persists the payment row
     * and returns it.
     */
    protected function createPaymentForBooking(Booking $booking, float $amount): Payment
    {
        $reference = 'PAY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

        $payment = $this->payments->create([
            'booking_id' => $booking->id,
            'provider' => Payment::PROVIDER_BAKONG,
            'transaction_reference' => $reference,
            'transaction_id' => $reference,
            'currency' => config('bakong.currency', 'USD'),
            'amount' => $amount,
            'status' => Payment::STATUS_PENDING,
            'payment_status' => 'pending',
            'expires_at' => now()->addMinutes((int) config('bakong.qr_expiration_minutes', 15)),
        ]);

        // Generate the KHQR LOCALLY with the PHP KHQR SDK (no server-side
        // Bakong QR endpoint is involved). This happens inside the transaction
        // so the booking + payment roll back together if local QR generation
        // unexpectedly fails.
        $qr = $this->bakongService->generateKhqr(
            $amount,
            $reference,
            ['booking_number' => $booking->booking_number]
        );

        $payment->update([
            'bakong_md5' => $qr['md5'],
            'qr_payload' => $qr['khqr'],
            'raw_request' => [],
            'expires_at' => $qr['expires_at'],
        ]);

        return $payment;
    }
}
