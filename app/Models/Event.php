<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
        'rejection_reason',
        'is_trending',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_trending' => 'boolean',
    ];

    /**
     * Always serialize the computed `is_upcoming` flag so every consumer
     * (admin dashboard, homepage, event cards, event detail) reads the same
     * date-derived value — including environments where no `is_upcoming`
     * column exists.
     *
     * @var list<string>
     */
    protected $appends = [
        'is_upcoming',
        'is_expired',
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

    /**
     * Primary cover/poster image (lowest sort_order, e.g. 1).
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(EventImg::class)
            ->orderBy('sort_order', 'asc');
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
     * Events treated as "Upcoming": published (which already excludes
     * cancelled events), whose start date has not passed yet AND that have
     * not ended already (end datetime in the past = past event).
     *
     * Derived entirely from the event's own dates — there is no manual
     * upcoming flag — so it can never go stale and is the single source
     * of truth shared by the admin dashboard and the customer homepage.
     *
     * Uses toDateString() so the comparison stays a plain DATE on
     * PostgreSQL and never picks up a time-of-day offset.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->published()
            ->where('start_date', '>=', today()->toDateString())
            ->where(function (Builder $q) {
                // An event that ended earlier today (end_time already passed)
                // must leave the upcoming listings too, not just tomorrow's.
                // A missing end_date is tolerated and treated as not-yet-ended
                // (the start date is already guaranteed to be today-or-later).
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>', today()->toDateString())
                    ->orWhere(function (Builder $q) {
                        $q->where('end_date', today()->toDateString())
                            ->where(function (Builder $q) {
                                $q->whereNull('end_time')
                                    ->orWhereTime('end_time', '>', now()->format('H:i:s'));
                            });
                    });
            });
    }

    /**
     * Whether this event is "Upcoming" right now. Computed, never stored:
     * an event is upcoming when it is published, its start date has not
     * passed and its end datetime is still in the future. Exposing it under
     * `is_upcoming` gives frontends one field that always matches the same
     * rule used by the upcoming filter/listings.
     */
    public function getIsUpcomingAttribute(): bool
    {
        return $this->status === 'published'
            && $this->start_date?->startOfDay()->gte(today()) === true
            && ! $this->is_expired;
    }

    /**
     * The real end timestamp of the event (end_date + end_time) as UTC,
     * matching the convention used by TicketExpirationService. Null end_time
     * falls back to the end of the day so a date-only event never reads as
     * "already ended" at 00:00:01.
     */
    public function endsAt(): ?Carbon
    {
        if ($this->end_date === null) {
            return null;
        }

        return Carbon::parse($this->end_date->toDateString())
            ->setTimeFromTimeString((string) ($this->end_time ?: '23:59:59'))
            ->utc();
    }

    /**
     * Whether the event has already finished: current UTC datetime passed
     * end_date + end_time. Exposed as `is_expired` on every serialized event
     * so frontends can render an Expired/Past state and disable booking.
     */
    public function isExpired(): bool
    {
        $endsAt = $this->endsAt();

        return $endsAt !== null && $endsAt->lt(Carbon::now('UTC'));
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }
}
