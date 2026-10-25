<?php

namespace App\Models;

use App\Casts\EncryptedCredentials;
use App\Enums\MarketplaceAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user's connection to a marketplace: contributor ID and upload credentials.
 *
 * @property int $id
 * @property int $user_id
 * @property int $marketplace_id
 * @property string|null $contributor_id Author ID on the marketplace side
 * @property string $label User-defined account name
 * @property string|null $host Upload server; the marketplace default when not changed
 * @property int|null $port
 * @property string|null $login
 * @property array|null $credentials Secrets only (password, later an SFTP key), encrypted with CREDENTIALS_KEY
 * @property array $settings Per-account overrides of marketplaces.upload_defaults
 * @property MarketplaceAccountStatus $status
 * @property Carbon|null $last_verified_at
 * @property string|null $last_error Reason of the last failed check: auth | connect | other
 * @property Carbon|null $credentials_changed_at
 */
class MarketplaceAccount extends Model
{
    protected $fillable = [
        'marketplace_id',
        'contributor_id',
        'label',
        'host',
        'port',
        'login',
        'credentials',
        'settings',
        'status',
        'last_verified_at',
        'last_error',
        'credentials_changed_at',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected $attributes = [
        'settings' => '{}',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => EncryptedCredentials::class,
            'settings' => 'array',
            'status' => MarketplaceAccountStatus::class,
            'port' => 'integer',
            'last_verified_at' => 'datetime',
            'credentials_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(AssetListing::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', MarketplaceAccountStatus::Active);
    }
}
