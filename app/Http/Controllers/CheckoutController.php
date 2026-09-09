<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
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
 *     -> generate KHQR via Bakong
 *     -> persist QR payload / MD5 / expiration
 *     -> commit transaction
 *     -> return booking + payment + QR to the client.
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
                // Lock the ticket row to prevent overselling under concurrency.
                $ticketType = TicketType::lockForUpdate()->findOrFail($validated['ticket_type_id']);

                if ($ticketType->event_id !== (int) $validated['event_id']) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator([], []),
                        response()->json([
                            'success' => false,
                            'message' => 'The ticket type does not belong to the selected event.',
                        ], 422)
                    );
                }

                if ($ticketType->status !== 'active') {
                    throw new \RuntimeException('This ticket type is not currently available for sale.');
                }

                $available = (int) $ticketType->quantity - (int) $ticketType->sold_quantity;
                if ($validated['quantity'] > $available) {
                    throw new \RuntimeException('Not enough tickets available.');
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

                // Call Bakong inside the transaction so that a failed QR
                // generation rolls the whole checkout back atomically.
                try {
                    $qr = $this->bakongService->generateQr(
                        $totalAmount,
                        $reference,
                        ['booking_number' => $booking->booking_number]
                    );
                } catch (BakongException $e) {
                    throw $e;
                }

                $payment->update([
                    'bakong_md5' => $qr['md5'],
                    'qr_payload' => $qr['qr'],
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
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getHttpStatus());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $e->getResponse();
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::channel('bakong')->info('Checkout completed.', [
            'booking_id' => $result['booking']->id,
            'payment_id' => $result['payment']->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Checkout initiated. Scan the KHQR to pay.',
            'data' => [
                'booking' => $result['booking'],
                'payment' => new PaymentResource($result['payment']->load('booking')),
                'qr_payload' => $result['qr']['qr'],
                'expires_at' => $result['payment']->expires_at?->toIso8601String(),
                'amount' => $result['payment']->amount,
                'currency' => $result['payment']->currency,
                'poll_interval_seconds' => 10,
            ],
        ], 201);
    }
}
