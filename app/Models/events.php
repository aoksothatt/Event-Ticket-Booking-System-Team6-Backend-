<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class events extends Model
{
    //
    protected $fillable = [
        'organizer_id',
        'category_id',
        'venue_id',
        'title',
        'slug',
        'description',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'banner',
        'status',
    ];
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(organizer::class);
    }
    public function category(): BelongsTo
    {
        return $this->belongsTo(category::class);
    }
    public function venue(): BelongsTo
    {
        return $this->belongsTo(venues::class);
    }
    public function images(): BelongsTo
    {
        return $this->belongsTo (event_img::class);

    }
    public function ticket_type(): BelongsTo
    {
        return $this->belongsTo(ticket_type::class);
    }
    public function booking(): BelongsTo 
    {
        return $this->belongsTo (booking::class);
    }
}
