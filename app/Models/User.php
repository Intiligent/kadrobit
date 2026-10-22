<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'avatar',
        'password',
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
     * Store the username as a canonical lowercase handle.
     */
    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : strtolower(trim($value)),
        );
    }

    /**
     * Build a unique, validation-safe username from an arbitrary seed.
     * Used when an account is created without an explicit handle (e.g. OAuth).
     */
    public static function generateUsername(string $seed): string
    {
        $base = trim(strtolower(preg_replace('/[^a-z0-9_.-]+/i', '', $seed)), '._-');

        if (strlen($base) < 3) {
            $base = 'user'.$base;
        }

        $base = substr($base, 0, 20);
        $username = $base;
        $suffix = 1;

        while (static::where('username', $username)->exists()) {
            $tail = (string) $suffix++;
            $username = substr($base, 0, 20 - strlen($tail)).$tail;
        }

        return $username;
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function marketplaceAccounts(): HasMany
    {
        return $this->hasMany(MarketplaceAccount::class);
    }
}
