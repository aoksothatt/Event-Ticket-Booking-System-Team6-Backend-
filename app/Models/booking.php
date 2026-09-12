<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    /** PostgreSQL preserves this capitalized table name because Laravel quotes it. */
    protected $table = 'Booking';

    protected $fillable = [
        'booking_number',
        'user_id',
        'event_id',
        'booking_date',
        'total_amount',
        'discount',
        'service_fee',
        'status',
    ];

    protected $casts = [
        'booking_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'service_fee' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payments::class, 'booking_id');
    }

    /**
     * The provider-agnostic payment records for this booking.
     */
    public function paymentRecords(): HasMany
    {
        return $this->hasMany(Payment::class, 'booking_id');
    }

    /**
     * The most recent active payment for this booking (used for polling).
     */
    public function latestPayment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Payment::class, 'booking_id')
            ->latestOfMany();
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
