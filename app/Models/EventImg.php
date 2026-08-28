<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Event;

class EventImg extends Model
{
    //
    protected $fillable = [
        'event_id',
        'image',
        'sort_order',
    ];
    protected $casts = [
        'sort_order' => 'integer'
    ];
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
