<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class check_in extends Model
{
    //
    protected $fillable = [
        'booking_id',
        'check_by',
        'check_in_at',
        'status',
    ];
    protected $casts = [
        'checked_in'=> 'dateime',
    ];
    public function booking(): BelongsTo
    {
        return $this->belongsTo(booking::class);
    }
    public function agent(): BelongsTo 
    {
        return $this->belongsTo(user::class, 'checked_by');
    }
}
