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
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        // holding a short-lived lock keyed on the user for this request.
        $lockKey = 'checkout:' . $user->id . ':' . $request->input('event_id') . ':' . $request->input('ticket_type_id');
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

        try {
            $result = DB::transaction(function () use ($validated, $user) {
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

                // Lock the ticket row to prevent overselling under concurrency.
                $ticketType = TicketType::lockForUpdate()->find($validated['ticket_type_id']);

                if (! $ticketType) {
                    throw new \RuntimeException('This ticket type is no longer available.');
                }

                if ($ticketType->event_id !== (int) $validated['event_id']) {
                    throw new \RuntimeException('The selected ticket does not belong to this event.');
                }

                if ($ticketType->status !== 'active') {
                    throw new \RuntimeException('This ticket type is not currently on sale.');
                }

                $available = (int) $ticketType->quantity - (int) $ticketType->sold_quantity;
                if ($validated['quantity'] > $available) {
                    if ($available === 0) {
                        throw new \RuntimeException('Sorry, this ticket type is sold out.');
                    }
                    throw new \RuntimeException(
                        "Only {$available} ticket(s) remaining. You requested {$validated['quantity']}."
                    );
                }

                // Authoritative pricing — never trust a client-supplied amount.
                $subtotal = (float) $ticketType->price * (int) $validated['quantity'];
                $discount = 0.0;
                $serviceFee = 0.0;
                $totalAmount = $subtotal + $serviceFee - $discount;

                $booking = Booking::create([
                    'booking_number' => 'BK-' . strtoupper(Str::random(10)),
                    'user_id' => $user->id,
                    'event_id' => (int) $validated['event_id'],
                    'booking_date' => now(),
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'service_fee' => $serviceFee,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                ]);

                // Reserve inventory.
                $ticketType->increment('sold_quantity', (int) $validated['quantity']);

                $booking->items()->create([
                    'ticket_type_id' => $ticketType->id,
                    'quantity' => (int) $validated['quantity'],
                    'unit_price' => $ticketType->price,
                    'subtotal' => $subtotal,
                ]);

                $reference = 'TXN-' . strtoupper(Str::random(12));

                $payment = $this->payments->create([
                    'booking_id' => $booking->id,
                    'provider' => Payment::PROVIDER_BAKONG,
                    'transaction_reference' => $reference,
                    'transaction_id' => $reference,
                    'currency' => config('bakong.currency', 'USD'),
                    'amount' => $totalAmount,
                    'status' => Payment::STATUS_PENDING,
                    'payment_status' => 'pending',
                    'expires_at' => now()->addMinutes((int) config('bakong.qr_expiration_minutes', 15)),
                ]);

                // Generate the KHQR LOCALLY with the PHP KHQR SDK (no server
                // side Bakong QR endpoint is involved). This happens inside
                // the transaction so the booking + payment roll back together
                // if local QR generation unexpectedly fails.
                try {
                    $qr = $this->bakongService->generateKhqr(
                        $totalAmount,
                        $reference,
                        ['booking_number' => $booking->booking_number]
                    );
                } catch (BakongException $e) {
                    throw $e;
                }

                $payment->update([
                    'bakong_md5' => $qr['md5'],
                    'qr_payload' => $qr['khqr'],
                    'raw_request' => [],
                    'expires_at' => $qr['expires_at'],
                ]);

                return [
                    'booking' => $booking->load('event', 'items.ticketType'),
                    'payment' => $payment,
                    'qr' => $qr,
                ];
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
        $deeplink = $this->bakongService->generateDeeplink($result['qr']['khqr'], [
            'booking_number' => $result['booking']->booking_number,
        ]);

        if (is_string($deeplink) && $deeplink !== '') {
            $result['payment']->update(['deeplink' => $deeplink]);
        }

        Log::channel('bakong')->info('Checkout completed.', [
            'booking_id' => $result['booking']->id,
            'payment_id' => $result['payment']->id,
            'has_deeplink' => is_string($deeplink) && $deeplink !== '',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Checkout initiated. Scan the KHQR to pay.',
            'data' => [
                'booking' => $result['booking'],
                'payment' => new PaymentResource($result['payment']->fresh('booking')),
                'qr_payload' => $result['qr']['khqr'],
                'md5' => $result['qr']['md5'],
                'deeplink' => $deeplink,
                'expires_at' => $result['payment']->expires_at?->toIso8601String(),
                'amount' => $result['payment']->amount,
                'currency' => $result['payment']->currency,
                'poll_interval_seconds' => 10,
            ],
        ], 201);
    }
}
