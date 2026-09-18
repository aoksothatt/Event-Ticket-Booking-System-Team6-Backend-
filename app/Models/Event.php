<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'events';

    /** @var list<string> */
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
        'is_trending',
        'is_upcoming',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_trending' => 'boolean',
        'is_upcoming' => 'boolean',
    ];

    /* ---------------------------- Relationships --------------------------- */

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'organizer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(EventImg::class, 'event_id');
    }

    /**
     * Readable alias for the event gallery.
     */
    public function eventImages(): HasMany
    {
        return $this->images();
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class, 'event_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'event_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'event_id');
    }

    /**
     * Users who favorited this event (event_favorites pivot table).
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_favorites')
            ->withTimestamps();
    }

    /* -------------------------------- Scopes ------------------------------ */

    /**
     * Only events that are publicly visible on the homepage.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Manual admin pick for the homepage "Trending" section,
     * restricted to published events only.
     */
    public function scopeTrending(Builder $query): Builder
    {
        return $query->published()
            ->where('is_trending', true);
    }

    /**
     * Manual admin pick for the homepage "Upcoming" banner, restricted to
     * published events only. No date logic — the admin decides what shows.
     */
    public function scopeUpcomingFlagged(Builder $query): Builder
    {
        return $query->published()
            ->where('is_upcoming', true);
    }

    /**
     * Events that have not ended yet (end_date is today or later).
     *
     * Uses toDateString() so the comparison stays a plain DATE on
     * PostgreSQL and never picks up a time-of-day offset.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('end_date', '>=', today()->toDateString());
    }
}
