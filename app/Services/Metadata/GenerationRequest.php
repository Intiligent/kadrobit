<?php

namespace App\Services\Metadata;

use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\MarketplaceCategory;
use Illuminate\Support\Carbon;

/**
 * Everything a provider gets for one work: the image and what the model cannot see in it.
 */
final readonly class GenerationRequest
{
    /**
     * @param  string  $image  JPEG bytes, already downscaled (ImagePreparer)
     * @param  array<string, array<string, string>>  $categories  Category names by external_id, by marketplace code
     * @param  array<string, int>  $categoriesMax  How many categories each marketplace takes
     */
    public function __construct(
        public string $image,
        public MediaType $type,
        public ?string $filename = null,
        public ?Carbon $capturedAt = null,
        public ?array $location = null,
        public array $categories = [],
        public array $categoriesMax = [],
    ) {}

    /**
     * For a work: categories of the user's turned-on marketplaces that apply to its type.
     */
    public static function forAsset(Asset $asset, string $image): self
    {
        $marketplaces = $asset->user->marketplaceAccounts()->active()->with('marketplace')->get()
            ->pluck('marketplace')->unique('id');

        $categories = [];
        $max = [];

        foreach ($marketplaces as $marketplace) {
            $list = MarketplaceCategory::query()->where('marketplace_id', $marketplace->id)
                ->forMediaType($asset->type)->orderBy('name')->pluck('name', 'external_id')->all();

            if ($list) {
                $categories[$marketplace->code] = array_map('strval', $list);
                $max[$marketplace->code] = (int) ($marketplace->metadata_rules['categories_max'] ?? 1);
            }
        }

        return new self(
            image: $image,
            type: $asset->type,
            filename: $asset->original_filename,
            capturedAt: $asset->captured_at,
            location: $asset->location,
            categories: $categories,
            categoriesMax: $max,
        );
    }
}
