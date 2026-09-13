<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment
 *
 * Represents a single payment attempt for a booking. This model is the
 * provider-agnostic representation of a transaction. It is provider-agnostic
 * by design so that additional payment gateways (ABA, Stripe, PayPal, Wing…)
 * can be added later without changing this model's contract.
 *
 * @property int    $id
 * @property int    $booking_id
 * @property string $provider
 * @property string $transaction_reference
 * @property string|null $bakong_md5
 * @property string|null $bakong_transaction_id
 * @property string|null $qr_payload
 * @property string|null $deeplink
 * @property string|null $currency
 * @property string $amount
 * @property string $status   pending|paid|failed|expired|cancelled|held
 * @property array|null $raw_request
 * @property array|null $raw_response
 * @property string|null $paid_at
 * @property string|null $expires_at
 */
class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_HELD = 'held';

    public const PROVIDER_BAKONG = 'bakong';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_HELD,
    ];

    protected $fillable = [
        'booking_id',
        'provider',
        'transaction_reference',
        'transaction_id',
        'bakong_md5',
        'bakong_transaction_id',
        'qr_payload',
        'deeplink',
        'currency',
        'amount',
        'status',
        'payment_method',
        'payment_status',
        'raw_request',
        'raw_response',
        'paid_at',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_request' => 'array',
        'raw_response' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * True when the payment was received but cannot be auto-verified through
     * the gateway (held for manual/administrative confirmation).
     */
    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    public function isExpired(): bool
    {
        // A paid payment is never "expired" even after expires_at passes.
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        if ($this->status === self::STATUS_PAID || $this->status === self::STATUS_HELD) {
            return false;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
