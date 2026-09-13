<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    protected $fillable = [
        'booking_id',
        'ticket_id',
        'checked_by',
        'checked_in_at',
        'status',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
