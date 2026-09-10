<?php

namespace App\Services\Bakong;

use App\Mail\TicketIssued;
use App\Models\Booking;
use App\Models\Payment;
use App\Repositories\PaymentRepository;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * PaymentVerificationService
 *
 * Coordinates the verification of a payment against the Bakong gateway and,
 * on success, the idempotent confirmation of the payment, the booking and
 * the generation of tickets.
 *
 * All confirmation logic is idempotent: running it repeatedly (polling,
 * manual verify, webhook re-delivery) never produces duplicate payments,
 * duplicate ticket generations or inconsistent state.
 */
class PaymentVerificationService
{
    public function __construct(
        private readonly BakongService $bakongService,
        private readonly PaymentRepository $payments,
        private readonly TicketService $tickets
    ) {}

    /**
     * Verify a payment against Bakong and update local state.
     *
     * @return array{status: string, changed: bool, payment: Payment, booking: ?Booking}
     */
    public function verify(Payment $payment): array
    {
        // Already resolved — nothing to do (idempotency for replays).
        if ($payment->isPaid()) {
            return ['status' => $payment->status, 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        if ($payment->isExpired()) {
            $this->payments->markExpired($payment);
            return ['status' => $payment->status, 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        if ($payment->bakong_md5 === null) {
            Log::channel('bakong')->warning('Cannot verify payment without a bakong_md5.', ['payment_id' => $payment->id]);
            throw new BakongException('This payment has no gateway reference to verify.', 422);
        }

        try {
            $result = $this->bakongService->checkTransactionByMd5($payment->bakong_md5);
        } catch (BakongException $e) {
            Log::channel('bakong')->warning('Verification failed for payment.', [
                'payment_id' => $payment->id,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->payments->storeRawResponse($payment, $result['raw']);
        if ($result['transaction_id']) {
            $this->payments->setBakongTransactionId($payment, $result['transaction_id']);
        }

        if ($result['status'] !== Payment::STATUS_PAID) {
            return ['status' => $result['status'], 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        // Confirmed paid — do the transactional, idempotent confirmation.
        $confirmed = DB::transaction(function () use ($payment, $result) {
            return $this->confirmPaid($payment, $result);
        });

        return ['status' => $confirmed['payment']->status, 'changed' => true, 'payment' => $confirmed['payment'], 'booking' => $confirmed['booking']];
    }

    /**
     * Confirm a successfully-paid payment: update payment + booking and
     * generate tickets. Idempotent under concurrent execution via a lock on
     * the payment row.
     *
     * @return array{payment: Payment, booking: Booking}
     */
    protected function confirmPaid(Payment $payment, array $result): array
    {
        $payment = Payment::whereKey($payment->id)->lockForUpdate()->first() ?? $payment;

        if (! $payment->isPaid()) {
            $payment->update([
                'status' => Payment::STATUS_PAID,
                'payment_status' => 'paid',
                'bakong_transaction_id' => $result['transaction_id'] ?? $payment->bakong_transaction_id,
                'paid_at' => now(),
            ]);
        }

        $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->first();
        $changed = false;

        if ($booking && $booking->status !== 'paid' && $booking->status !== 'confirmed') {
            $booking->update(['status' => 'paid']);
            $changed = true;
        }

        // Generate tickets exactly once.
        $this->tickets->generateForBooking($booking);

        if ($changed) {
            Log::channel('bakong')->info('Payment confirmed by Bakong.', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
            ]);

            $this->dispatchTicketEmail($booking);
        }

        return [
            'payment' => $payment,
            'booking' => $booking,
        ];
    }

    /**
     * Fire the "your tickets are ready" email. Failures are logged but never
     * fatal to the confirmation flow.
     */
    protected function dispatchTicketEmail(Booking $booking): void
    {
        try {
            $tickets = $booking->tickets()->with('ticketType')->get();

            if ($tickets->isNotEmpty() && $booking->user) {
                Mail::to($booking->user)
                    ->send(new TicketIssued($booking, $tickets));
            }
        } catch (\Throwable $e) {
            Log::channel('bakong')->error('Could not send ticket email.', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
