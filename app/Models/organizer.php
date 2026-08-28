<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Organizer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'company_logo',
        'phone',
        'website',
        'description',
        'is_verified'
    ];

    // Organizer -> User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    //Organizer -> events

    public function events()
    {
        return $this->hasMany(Event::class);
    }
}
