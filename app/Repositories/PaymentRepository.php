<?php

namespace App\Repositories;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * PaymentRepository
 *
 * Centralises data access and ORM detail for Payment records so that
 * controllers/services stay thin and the storage implementation can be
 * swapped without touching business logic.
 */
class PaymentRepository
{
    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function find(int $id): ?Payment
    {
        return Payment::with('booking')->find($id);
    }

    public function findOrFail(int $id): Payment
    {
        return Payment::with('booking')->findOrFail($id);
    }

    /**
     * Locate the active (pending) payment for a booking.
     */
    public function activePaymentForBooking(int $bookingId): ?Payment
    {
        return Payment::with('booking')
            ->where('booking_id', $bookingId)
            ->whereIn('status', [Payment::STATUS_PENDING])
            ->latest('id')
            ->first();
    }

    public function storeRawResponse(Payment $payment, array $raw): Payment
    {
        $payment->update([
            'raw_response' => $payment->raw_response
                ? array_merge((array) $payment->raw_response, [$raw])
                : [$raw],
        ]);

        return $payment;
    }

    public function setBakongTransactionId(Payment $payment, string $transactionId): Payment
    {
        $payment->update(['bakong_transaction_id' => $transactionId]);
        return $payment;
    }

    public function markExpired(Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_PAID) {
            $payment->update([
                'status' => Payment::STATUS_EXPIRED,
                'payment_status' => 'expired',
            ]);
        }
        return $payment;
    }

    public function markFailed(Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_PAID) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'payment_status' => 'failed',
            ]);
        }
        return $payment;
    }

    public function markCancelled(Payment $payment): Payment
    {
        $payment->update([
            'status' => Payment::STATUS_CANCELLED,
            'payment_status' => 'cancelled',
        ]);
        return $payment;
    }

    /**
     * Mark a payment as held for manual review. This is the terminal state
     * for payments Bakong refuses to auto-verify (static-QR case): the
     * customer may already have paid, verification is re-attempted on the
     * gateway no more, and an admin must confirm the received funds.
     */
    public function markHeld(Payment $payment, ?string $reason = null): Payment
    {
        if ($payment->status !== Payment::STATUS_PAID) {
            $payment->update([
                'status' => Payment::STATUS_HELD,
                'payment_status' => 'held',
            ]);

            Log::channel('bakong')->warning('Payment held for manual review.', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'reason' => $reason,
            ]);
        }

        return $payment;
    }

    /**
     * Idempotency guard: return the existing pending payment for a booking if
     * one already exists, otherwise null.
     */
    public function existingPending(int $bookingId): ?Payment
    {
        return Payment::where('booking_id', $bookingId)
            ->where('status', Payment::STATUS_PENDING)
            ->latest('id')
            ->first();
    }
}
