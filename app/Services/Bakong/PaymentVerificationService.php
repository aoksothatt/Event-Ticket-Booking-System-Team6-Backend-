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

        // Persist terminal non-paid outcomes so local state (and the admin
        // dashboard) reflects what Bakong reports instead of staying "pending".
        if ($result['status'] === Payment::STATUS_FAILED) {
            $this->payments->markFailed($payment);
            return ['status' => $payment->status, 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        if ($result['status'] === Payment::STATUS_EXPIRED) {
            $this->payments->markExpired($payment);
            return ['status' => $payment->status, 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        if ($result['status'] !== Payment::STATUS_PAID) {
            return ['status' => $result['status'], 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        // Security gate: only confirm when the Bakong transaction actually
        // matches this payment's expected amount and currency. A transaction
        // for a different amount (e.g. $5.00 against a $25.00 booking) must
        // NEVER confirm the booking.
        if ($this->transactionAmountMismatch($payment, $result)) {
            Log::channel('bakong')->error('Payment confirmation blocked: Bakong transaction does not match the payment.', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'expected_amount' => $payment->amount,
                'expected_currency' => $payment->currency,
                'bakong_amount' => $result['amount'] ?? null,
                'bakong_currency' => $result['currency'] ?? null,
            ]);

            $this->payments->markFailed($payment);

            return ['status' => $payment->status, 'changed' => false, 'payment' => $payment, 'booking' => $payment->booking];
        }

        // Confirmed paid — do the transactional, idempotent confirmation.
        $confirmed = DB::transaction(function () use ($payment, $result) {
            return $this->confirmPaid($payment, $result);
        });

        return ['status' => $confirmed['payment']->status, 'changed' => true, 'payment' => $confirmed['payment'], 'booking' => $confirmed['booking']];
    }

    /**
     * Returns true when the Bakong transaction amount/currency disagree with
     * the expected payment. When Bakong does not return an amount/currency at
     * all there is nothing to compare, so the check is skipped (a warning is
     * logged instead — this is more lenient than blocking a real payment).
     */
    protected function transactionAmountMismatch(Payment $payment, array $result): bool
    {
        $bakongAmount = $result['amount'] ?? null;
        $bakongCurrency = $result['currency'] ?? null;

        if (($bakongAmount === null || $bakongAmount === '') && ($bakongCurrency === null || $bakongCurrency === '')) {
            Log::channel('bakong')->warning('Bakong transaction returned no amount/currency — amount verification skipped.', [
                'payment_id' => $payment->id,
            ]);

            return false;
        }

        if ($bakongCurrency !== null && $bakongCurrency !== '') {
            $expected = strtoupper((string) $payment->currency ?: config('bakong.currency', 'USD'));
            $actual = $this->normalizeCurrency($bakongCurrency);

            if ($actual !== '' && $actual !== $expected) {
                return true;
            }
        }

        if ($bakongAmount !== null && $bakongAmount !== '') {
            $expected = (float) $payment->amount;
            $actual = (float) $bakongAmount;

            if (abs(round($expected, 2) - round($actual, 2)) >= 0.005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bakong may report the transaction currency as an ISO code ("USD"/"KHR")
     * or as the numeric ISO-4217 code ("840"/"116"). Normalise both to the
     * uppercase ISO code used internally.
     */
    protected function normalizeCurrency(mixed $value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit((string) $value))) {
            $codes = array_flip(config('bakong.currency_codes', []));

            return (string) ($codes[(int) $value] ?? $value);
        }

        return strtoupper((string) $value);
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

        if ($booking && ! in_array($booking->status, ['paid', 'confirmed'], true)) {
            $booking->update(['status' => 'confirmed']);
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
