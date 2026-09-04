<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    protected $fillable = [
        'booking_id',
        'ticket_type_id',
        'quantity',
        'unit_price',
        'subtotal',

    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(booking::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
