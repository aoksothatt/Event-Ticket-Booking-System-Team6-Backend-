<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
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
        if ($this->role === 'admin') {
            return true;
        }

        return in_array($permission, config("permissions.roles.{$this->role}", []), true);
    }

    /**
     * Check if the user has ANY of the given permissions.
     * Admin is a super role and passes every check.
     */
    public function hasAnyPermission(string ...$permissions): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        $granted = config("permissions.roles.{$this->role}", []);

        return count(array_intersect($permissions, $granted)) > 0;
    }
}
