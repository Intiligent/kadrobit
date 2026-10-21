<?php

namespace App\Models;

use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category from a marketplace's own fixed list. Seeded, not editable by users.
 *
 * @property int $id
 * @property int $marketplace_id
 * @property int|null $parent_id
 * @property string $external_id Category ID (or code) on the marketplace side
 * @property string $name
 * @property \Illuminate\Support\Collection<int, MediaType> $media_types Content types the category applies to
 */
class MarketplaceCategory extends Model
{
    protected $fillable = [
        'marketplace_id',
        'parent_id',
        'external_id',
        'name',
        'media_types',
    ];

    protected function casts(): array
    {
        return [
            'media_types' => AsEnumCollection::of(MediaType::class),
        ];
    }

    public function scopeForMediaType(Builder $query, MediaType $type): void
    {
        $query->whereJsonContains('media_types', $type->value);
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
