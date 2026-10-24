<?php

namespace App\Models;

use App\Enums\MediaType;
use App\Enums\MetadataDelivery;
use App\Enums\UploadProtocol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Stock marketplace reference data. Seeded, not editable by users.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $logo Path on the "public" disk (storage/app/public), e.g. marketplaces/adobe_stock.svg
 * @property-read string|null $logo_url
 * @property bool $is_active
 * @property Collection<int, MediaType> $supported_types
 * @property UploadProtocol $upload_protocol
 * @property array $upload_defaults
 * @property MetadataDelivery|null $metadata_delivery
 * @property array $metadata_rules
 * @property array $file_rules
 */
class Marketplace extends Model
{
    protected $fillable = [
        'code',
        'name',
        'logo',
        'is_active',
        'supported_types',
        'upload_protocol',
        'upload_defaults',
        'metadata_delivery',
        'metadata_rules',
        'file_rules',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'supported_types' => AsEnumCollection::of(MediaType::class),
            'upload_protocol' => UploadProtocol::class,
            'upload_defaults' => 'array',
            'metadata_delivery' => MetadataDelivery::class,
            'metadata_rules' => 'array',
            'file_rules' => 'array',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(MarketplaceCategory::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MarketplaceAccount::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->logo ? Storage::disk('public')->url($this->logo) : null,
        );
    }

    /**
     * The upload login is the contributor ID (Adobe Stock): one field in the connect form, not two.
     */
    public function loginIsContributorId(): bool
    {
        return (bool) ($this->upload_defaults['login_is_contributor_id'] ?? false);
    }

    public function supports(MediaType $type): bool
    {
        return $this->supported_types->contains($type);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
