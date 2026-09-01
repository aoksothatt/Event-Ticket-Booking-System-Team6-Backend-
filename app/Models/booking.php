<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_number',
        'user_id',
        'event_id',
        'booking_date',
        'total_amount',
        'status',
    ];
    protected $casts = [
        'booking_date' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

   
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    
    public function event(): BelongsTo
    {
        return $this->belongsTo(events::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(booking_item::class);
    }
}