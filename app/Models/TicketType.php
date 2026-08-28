<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketType extends Model
{
    //
    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price',
        'quantity',
        'sold_quantity',
        'status',
    ];
    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'sold_quantity' => 'integer',
    ];
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
    public function BookingIem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }
}
