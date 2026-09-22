<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'user_id',
        'rating',
        'comment',
        'status',
    ];

    // Review → Event
    public function event()
    {
        return $this->belongsTo(events::class);
    }

    // Review → User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
