<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    public const DONE = 'DONE';

    public const ACTIVE = 'ACTIVE';

    public const USED = 'USED';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    public const REFUNDED = 'REFUNDED';

    protected $fillable = [
        'booking_id',
        'booking_item_id',
        'ticket_type_id',
        'user_id',
        'event_id',
        'ticket_code',
        'ticket_number',
        'qr_token',
        'qr_code',
        'status',
        'issued_at',
        'used_at',
        'expired_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    /**
     * Automatically log every status transition so the audit trail
     * is always up to date — even when status is changed directly.
     */
    protected static function booted(): void
    {
        static::updated(function (Ticket $ticket) {
            if ($ticket->wasChanged('status')) {
                $ticket->logs()->create([
                    'action' => $ticket->status,
                    'description' => "Ticket status changed from {$ticket->getOriginal('status')} to {$ticket->status}",
                    'actor_id' => auth()->id(),
                    'metadata' => [
                        'old_status' => $ticket->getOriginal('status'),
                        'new_status' => $ticket->status,
                        'ticket_code' => $ticket->ticket_code,
                    ],
                ]);
            }
        });
    }

    /* ---------------------------- Relationships --------------------------- */

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TicketLog::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function ticketCheckins(): HasMany
    {
        return $this->hasMany(TicketCheckin::class);
    }

    /* ------------------------------- Accesors ----------------------------- */

    /**
     * Human-friendly status (active/used/expired/cancelled/refunded).
     */
    public function getStatusLabelAttribute(): string
    {
        return strtolower($this->status);
    }

    /**
     * Whether the ticket is currently usable (DONE or ACTIVE and not expired/used).
     */
    public function getIsValidAttribute(): bool
    {
        return in_array($this->status, [self::DONE, self::ACTIVE], true)
            && $this->expired_at === null
            && $this->used_at === null;
    }

    /* -------------------------------- Scopes ------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeDone($query)
    {
        return $query->where('status', self::DONE);
    }

    public function scopeUsed($query)
    {
        return $query->where('status', self::USED);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::EXPIRED);
    }

    public function scopeVisibleToUser($query)
    {
        return $query->whereIn('status', [
            self::DONE,
            self::ACTIVE,
            self::USED,
        ]);
    }

    public function scopeHistory($query)
    {
        return $query->whereIn('status', [
            self::EXPIRED,
            self::CANCELLED,
            self::REFUNDED,
        ]);
    }

    /* ------------------------------ Helpers ------------------------------- */

    /**
     * Activate a DONE ticket (DONE → ACTIVE).
     */
    public function activate(?int $actorId = null): bool
    {
        if ($this->status !== self::DONE) {
            return false;
        }

        return $this->update(['status' => self::ACTIVE]);
    }

    /**
     * Mark this ticket as used (atomic, guarded against double use).
     */
    public function markAsUsed(?int $actorId = null): bool
    {
        if (! in_array($this->status, [self::ACTIVE, self::DONE], true)) {
            return false;
        }

        return $this->update([
            'status' => self::USED,
            'used_at' => now(),
        ]);
    }

    /**
     * Cancel this ticket if still active or done.
     */
    public function cancel(?int $actorId = null): bool
    {
        if (! in_array($this->status, [self::ACTIVE, self::DONE, self::EXPIRED], true)) {
            return false;
        }

        return $this->update(['status' => self::CANCELLED]);
    }
}
