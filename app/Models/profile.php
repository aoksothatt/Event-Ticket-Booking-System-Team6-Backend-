<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'phone',
        'gender',
        'dob',
        'address',
        'avatar'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
