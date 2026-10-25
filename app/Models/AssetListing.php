<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A work sent, or on its way, to one marketplace account (docs/publishing.md).
 *
 * @property int $id
 * @property int $asset_id
 * @property int $marketplace_account_id
 * @property ListingStatus $status
 * @property list<array{key: string, params: array<string, mixed>}> $warnings Checks that did not pass when it was queued
 * @property string|null $remote_filename Name of the file on the marketplace's server
 * @property array|null $metadata Metadata as sent, see snapshot()
 * @property string|null $error Reason of the last failure: auth | connect | other | account | unsupported | file
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AssetListing extends Model
{
    /** The account was turned off or lost its sign-in before the upload. Besides the ConnectionResult reasons. */
    public const ERROR_ACCOUNT = 'account';

    /** No delivery for the marketplace yet. */
    public const ERROR_UNSUPPORTED = 'unsupported';

    /** The work's file is missing or cannot be read. */
    public const ERROR_FILE = 'file';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'asset_id',
        'marketplace_account_id',
        'status',
        'warnings',
        'remote_filename',
        'metadata',
        'error',
        'attempts',
        'sent_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'warnings' => '[]',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'warnings' => 'array',
            'metadata' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MarketplaceAccount::class, 'marketplace_account_id');
    }

    /**
     * The metadata a marketplace gets from a work: what is compared to tell that a sent work was edited.
     *
     * @return array{title: string, description: string, keywords: list<string>, categories: list<string>, is_editorial: bool, is_ai_generated: bool}
     */
    public static function snapshot(Asset $asset, Marketplace $marketplace): array
    {
        return [
            'title' => (string) $asset->title,
            'description' => (string) $asset->description,
            'keywords' => array_values($asset->keywords ?? []),
            'categories' => array_values($asset->categories[$marketplace->code] ?? []),
            'is_editorial' => $asset->is_editorial,
            'is_ai_generated' => $asset->is_ai_generated,
        ];
    }

    /**
     * Sent, and the work has been edited since: the marketplace still has the old metadata.
     * Needs the asset and the account's marketplace.
     */
    public function isOutdated(): bool
    {
        return $this->status === ListingStatus::Sent
            && $this->metadata !== null
            && $this->metadata != self::snapshot($this->asset, $this->account->marketplace);
    }

    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', [ListingStatus::Queued, ListingStatus::Sending]);
    }
}
