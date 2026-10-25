<?php

namespace App\Models;

use App\Models\Concerns\HasSettings;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\EmailVerificationService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasSettings, HasUlids, Notifiable;

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
     * Generate a ULID for the "ulid" column; the primary key stays an auto-increment id.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

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
     * Send the branded password reset email instead of the framework default.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Confirmation by a 6-digit code (with a signed link as a fallback) instead of the framework's link-only email.
     * Called on registration through the Registered event.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerificationService::class)->issue($this);
    }

    /**
     * Store the email lowercase: PostgreSQL compares case-sensitively, and login looks it up lowercase.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : static::normalizeEmail($value),
        );
    }

    /**
     * The stored form of an email; form requests normalize input the same way before lookups.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
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

    /**
     * One of the user's works by its ulid; someone else's work is not found (404), not forbidden.
     * `$lock` holds the row until the transaction ends.
     */
    public function ownAsset(string $ulid, bool $lock = false): Asset
    {
        return $this->assets()->where('ulid', $ulid)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public function assetUploads(): HasMany
    {
        return $this->hasMany(AssetUpload::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function marketplaceAccounts(): HasMany
    {
        return $this->hasMany(MarketplaceAccount::class);
    }
}
