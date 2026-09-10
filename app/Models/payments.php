<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payments extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'provider',
        'payment_method',
        'transaction_id',
        'transaction_reference',
        'bakong_md5',
        'bakong_transaction_id',
        'qr_payload',
        'amount',
        'currency',
        'payment_status',
        'status',
        'raw_request',
        'raw_response',
        'paid_at',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'raw_request' => 'array',
        'raw_response' => 'array',
    ];

    // Payment -> Booking
    public function booking()
    {
        return $this->belongsTo(booking::class);
    }
}
