<?php

namespace App\Services\Metadata;

use App\Enums\GenerationTrigger;
use App\Enums\MetadataSource;
use App\Models\Asset;
use App\Models\AssetGeneration;
use App\Models\Marketplace;
use App\Models\MarketplaceCategory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Metadata generation for works: pays for a generation, keeps it in the history and applies it to the work.
 *
 * Applying follows the field sources (assets.metadata_sources):
 * - filling (automatic, after upload) writes only fields that are empty and not edited by the user;
 * - replacing (the user asked: regenerate or restore) writes the named fields whatever their source.
 * A generated empty value never clears a field.
 */
class AssetMetadataService
{
    /** Fields a generation writes. */
    public const FIELDS = ['title', 'description', 'keywords', 'categories'];

    /** A generation the user waits for in the request: one try, so it ends well within web server timeouts. */
    protected const WAITING_OVERRIDES = ['tries' => 1, 'timeout' => 30];

    public function __construct(
        protected MetadataGeneratorFactory $generators,
        protected ImagePreparer $images,
    ) {}

    /**
     * Whether a generation can be made at all: the configured provider has its key.
     */
    public function available(): bool
    {
        return $this->generators->make()->available();
    }

    /**
     * Whether a filling generation would write anything: a field that is empty and not the user's, or
     * a turned-on marketplace with a category list the work has no categories for. Otherwise it is not paid for.
     */
    public function needsFilling(Asset $asset): bool
    {
        $sources = $asset->metadata_sources ?? [];
        $open = fn (string $field) => ($sources[$field] ?? null) !== MetadataSource::User->value;

        foreach (['title', 'description', 'keywords'] as $field) {
            if ($open($field) && blank($asset->{$field})) {
                return true;
            }
        }

        if (! $open('categories')) {
            return false;
        }

        [$lists] = GenerationRequest::categoryLists($this->activeMarketplaces($asset), $asset->type);

        return (bool) array_diff_key($lists, $asset->categories ?? []);
    }

    /**
     * Generations the user may still make this month.
     */
    public function remaining(User $user): int
    {
        return max(0, config('metadata.monthly_limit') - AssetGeneration::query()->whereBelongsTo($user)->thisMonth()->count());
    }

    /**
     * @throws GenerationLimitReached
     * @throws GenerationException
     */
    public function generate(Asset $asset, GenerationTrigger $trigger, bool $waiting = false): AssetGeneration
    {
        if ($this->remaining($asset->user) < 1) {
            throw new GenerationLimitReached;
        }

        $generator = $this->generators->make(overrides: $waiting ? self::WAITING_OVERRIDES : []);
        $result = $generator->generate(GenerationRequest::forAsset($asset, $this->images->fromAsset($asset)));

        $generation = $asset->generations()->make([
            'user_id' => $asset->user_id,
            'trigger' => $trigger,
            'model' => $generator->name(),
            'result' => [
                'title' => $result->title,
                'description' => $result->description,
                'keywords' => $result->keywords,
                'categories' => $result->categories,
                'looks_editorial' => $result->looksEditorial,
                'fixes' => $result->fixes,
            ],
            'input_tokens' => $result->usage->input,
            'cached_input_tokens' => $result->usage->cachedInput,
            'output_tokens' => $result->usage->output,
            'reasoning_tokens' => $result->usage->reasoning,
            'cost_usd' => $result->cost(),
            'duration_ms' => $result->durationMs,
        ]);
        $generation->save();

        return $generation;
    }

    /**
     * Writes a generation into the work. Without $fields it fills; with them it replaces those fields.
     * The work is re-read under a lock: the user may have edited it while the model was answering.
     *
     * @param  list<string>|null  $fields
     */
    public function apply(Asset $asset, AssetGeneration $generation, ?array $fields = null): Asset
    {
        return DB::transaction(function () use ($asset, $generation, $fields) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $sources = $asset->metadata_sources;
            $result = $generation->result;

            foreach ($fields ?? self::FIELDS as $field) {
                $value = $field === 'categories'
                    ? $this->categories($asset->categories ?? [], $this->allowedCategories($asset, $result['categories'] ?? []), replace: $fields !== null)
                    : $result[$field] ?? null;

                $writable = $fields !== null
                    || (($sources[$field] ?? null) !== MetadataSource::User->value && ($field === 'categories' || blank($asset->{$field})));

                if (! $writable || blank($value) || $value === $asset->{$field}) {
                    continue;
                }

                $asset->{$field} = $value;
                $sources[$field] = MetadataSource::Ai->value;
            }

            $asset->metadata_sources = $sources;
            $asset->save();

            return $asset;
        });
    }

    /**
     * The generated categories that are still valid, as UpdateAssetRequest would accept them: marketplaces the user
     * is connected to, categories on their current list for the work type, within their limit. A generation restored
     * from the history may predate a disconnected marketplace or a changed list.
     *
     * @param  array<string, list<string>>  $categories
     * @return array<string, list<string>>
     */
    protected function allowedCategories(Asset $asset, array $categories): array
    {
        if (! $categories) {
            return [];
        }

        $marketplaces = Marketplace::query()
            ->whereIn('code', array_keys($categories))
            ->whereIn('id', $asset->user->marketplaceAccounts()->select('marketplace_id'))
            ->get();

        $allowed = [];

        foreach ($marketplaces as $marketplace) {
            $known = MarketplaceCategory::query()->where('marketplace_id', $marketplace->id)
                ->whereIn('external_id', $categories[$marketplace->code])->forMediaType($asset->type)
                ->pluck('external_id')->all();
            $valid = array_values(array_intersect($categories[$marketplace->code], $known));

            if ($valid) {
                $allowed[$marketplace->code] = array_slice($valid, 0, (int) ($marketplace->metadata_rules['categories_max'] ?? 1));
            }
        }

        return $allowed;
    }

    /**
     * @return Collection<int, Marketplace>
     */
    protected function activeMarketplaces(Asset $asset): Collection
    {
        return $asset->user->marketplaceAccounts()->active()->with('marketplace')->get()->pluck('marketplace')->unique('id');
    }

    /**
     * Categories by marketplace: filling adds only marketplaces that have none yet; replacing takes the
     * generated ones for every marketplace in the generation. Other marketplaces keep theirs.
     *
     * @param  array<string, list<string>>  $current
     * @param  array<string, list<string>>  $generated
     * @return array<string, list<string>>
     */
    protected function categories(array $current, array $generated, bool $replace): array
    {
        return $replace ? array_replace($current, $generated) : $current + $generated;
    }
}
