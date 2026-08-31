<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory;

    protected $table = 'venues';

    protected $fillable = [
        'name',
        'address',
        'city',
        'province',
        'country',
        'capacity',
        'description',
        'status',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
