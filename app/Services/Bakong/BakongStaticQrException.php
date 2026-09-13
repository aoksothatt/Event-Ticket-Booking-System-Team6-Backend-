<?php

namespace App\Services\Bakong;

use Throwable;

/**
 * Thrown when Bakong reports that the configured merchant/QR cannot be
 * verified through /v1/check_transaction_by_md5 — Bakong's "Sorry, the
 * system does not support static QR code" (error code 2) case.
 *
 * Bakong only supports md5-based transaction checks for dynamic KHQRs it
 * tracks. When this occurs the payment itself may still have been received
 * into the merchant's Bakong account, but it can never be auto-verified via
 * the gateway. PaymentVerificationService treats this as "held for review"
 * instead of a transient outage, so the customer is never told the gateway
 * is simply "temporarily unavailable" and free to pay again.
 *
 * The raw Bakong response body is carried along so the verifier can persist
 * an audit trail.
 */
class BakongStaticQrException extends BakongException
{
    /**
     * @param  array<string, mixed>  $body  The raw Bakong response body
     */
    public function __construct(
        string $message,
        public readonly array $body = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 422, $previous);
    }
}