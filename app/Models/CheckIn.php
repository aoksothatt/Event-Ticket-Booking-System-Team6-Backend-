<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    //
    protected $fillable = [
        'booking_id',
        'check_by',
        'check_in_at',
        'status',
    ];
    protected $casts = [
        'checked_in' => 'dateime',
    ];
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
