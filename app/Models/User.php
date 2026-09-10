<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'role',
        'status',
        'google_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // i add this two function
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the identifier that will be stored in the JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return custom claims for the JWT.
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function eventStaff(): HasMany
    {
        return $this->hasMany(EventStaff::class);
    }

    public function staffCheckins(): HasMany
    {
        return $this->hasMany(TicketCheckin::class, 'staff_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * The organizer profile owned by this user (organizer role).
     */
    public function organizerProfile(): HasOne
    {
        return $this->hasOne(Organizer::class);
    }

    /**
     * The organizer this user is acting for — organisers own a profile,
     * event staff inherit the organizer they are assigned to.
     */
    public function activeOrganizer(): ?Organizer
    {
        if ($this->role === Role::ORGANIZER->value) {
            return $this->organizerProfile;
        }

        return $this->eventStaff()
            ->where('is_active', true)
            ->first()?->organizer;
    }

    /**
     * Events the user has favorited.
     */
    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_favorites')
            ->withTimestamps();
    }

    /**
     * Make sure every user has a profile record.
     */
    protected static function booted(): void
    {
        static::created(function (User $user) {
            if (! $user->profile()->exists()) {
                $user->profile()->create();
            }
        });
    }

    /**
     * Check if the user has one of the given roles.
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Check if the user has a single permission.
     * Admin is a super role and passes every check.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->hasAnyPermission($permission);
    }

    /**
     * Check if the user has ANY of the given permissions.
     * Admin is a super role and passes every check.
     */
    public function hasAnyPermission(string ...$permissions): bool
    {
        if ($this->role === Role::ADMIN->value) {
            return true;
        }

        $granted = config("permissions.roles.{$this->role}", []);

        return count(array_intersect($permissions, $granted)) > 0;
    }

    /**
     * True when the user has the event_staff role AND is assigned to an organizer.
     */
    public function belongsToOrganizer(int $organizerId): bool
    {
        return $this->eventStaff()
            ->where('organizer_id', $organizerId)
            ->where('is_active', true)
            ->exists();
    }
}
