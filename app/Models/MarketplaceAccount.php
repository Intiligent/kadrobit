<?php

namespace App\Models;

use App\Enums\MarketplaceAccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's connection to a marketplace: contributor ID and upload credentials.
 *
 * @property int $id
 * @property int $user_id
 * @property int $marketplace_id
 * @property string|null $contributor_id Author ID on the marketplace side
 * @property string $label User-defined account name
 * @property array|null $credentials Encrypted: login, password, host, SFTP key
 * @property array $settings Per-account overrides of marketplaces.upload_defaults
 * @property MarketplaceAccountStatus $status
 * @property \Illuminate\Support\Carbon|null $last_verified_at
 */
class MarketplaceAccount extends Model
{
    protected $fillable = [
        'marketplace_id',
        'contributor_id',
        'label',
        'credentials',
        'settings',
        'status',
        'last_verified_at',
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
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'status' => MarketplaceAccountStatus::class,
            'last_verified_at' => 'datetime',
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

    public function scopeActive(Builder $query): void
    {
        $query->where('status', MarketplaceAccountStatus::Active);
    }
}
