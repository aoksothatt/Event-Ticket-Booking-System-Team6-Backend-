<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Repositories\PaymentRepository;
use App\Services\Bakong\BakongException;
use App\Services\Bakong\PaymentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * PaymentController
 *
 * Exposes the read/poll and verification surface for the payment module.
 * Verification itself is delegated to PaymentVerificationService so that the
 * same idempotent logic is reused by polling, manual verify and webhooks.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentVerificationService $verifier
    ) {}

    /**
     * GET /api/payments/{payment}
     * Fetch a payment's current (local) status for polling.
     */
    public function show(Request $request, int $payment)
    {
        $record = $this->payments->findOrFail($payment);

        if ($record->booking->user_id !== $request->user()->id
            && ! $request->user()->hasPermission('manage_payments')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view this payment.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment' => new PaymentResource($record),
                'status' => $record->status,
                'is_expired' => $record->isExpired(),
            ],
        ]);
    }

    /**
     * GET /api/payments/{payment}/status
     * Lightweight polling endpoint returning just the status + relevant flags.
     */
    public function status(Request $request, int $payment): JsonResponse
    {
        $record = $this->payments->findOrFail($payment);

        if ($record->booking->user_id !== $request->user()->id
            && ! $request->user()->hasPermission('manage_payments')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to view this payment.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment_id' => $record->id,
                'status' => $record->status,
                'is_expired' => $record->isExpired(),
                'paid_at' => $record->paid_at?->toIso8601String(),
                'expires_at' => $record->expires_at?->toIso8601String(),
                'booking_id' => $record->booking_id,
                'booking_status' => $record->booking->status,
            ],
        ]);
    }

    /**
     * POST /api/payments/{payment}/verify
     * Ask the backend to verify the payment with Bakong and confirm the
     * booking + tickets if the customer has paid. Idempotent.
     */
    public function verify(Request $request, int $payment): JsonResponse
    {
        $record = $this->payments->findOrFail($payment);

        if ($record->booking->user_id !== $request->user()->id
            && ! $request->user()->hasPermission('manage_payments')) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to verify this payment.',
            ], 403);
        }

        try {
            $result = $this->verifier->verify($record);
        } catch (BakongException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getHttpStatus());
        }

        $booking = $result['booking'];
        $ticketsGenerated = $result['changed'];

        return response()->json([
            'success' => true,
            'data' => [
                'payment' => new PaymentResource($result['payment']->fresh('booking')),
                'status' => $result['status'],
                'booking_status' => $booking?->status,
                'tickets_generated' => $ticketsGenerated,
                'tickets' => $booking
                    ? $booking->tickets()->with('ticketType')->get()
                    : [],
            ],
        ]);
    }

    /**
     * POST /api/payments/webhook
     * Bakong webhook callback. Public — authenticated by a shared secret,
     * then runs the same idempotent verification/confirmation pipeline.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('bakong.webhook_secret');

        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-Bakong-Signature', ''))) {
            Log::channel('bakong')->warning('Webhook rejected: bad signature.');
            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 403);
        }

        // Decode either a plain-JSON body or (if we must accept form posts) form fields.
        $payload = $request->json()->all();
        if (empty($payload)) {
            $payload = $request->all();
        }

        $md5 = $payload['md5'] ?? $payload['data']['md5'] ?? null;

        Log::channel('bakong')->info('Webhook received.', [
            'payload' => $payload,
        ]);

        if ($md5 === null) {
            return response()->json(['success' => false, 'message' => 'Missing md5.'], 422);
        }

        $payment = Payment::with('booking')
            ->where('bakong_md5', $md5)
            ->latest('id')
            ->first();

        if (! $payment) {
            Log::channel('bakong')->warning('Webhook: no payment found for md5.', ['md5' => $md5]);
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        try {
            $result = $this->verifier->verify($payment);
        } catch (BakongException $e) {
            Log::channel('bakong')->error('Webhook verification failed.', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getHttpStatus());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment_id' => $payment->id,
                'status' => $result['status'],
                'booking_status' => $result['booking']?->status,
            ],
        ]);
    }
}
