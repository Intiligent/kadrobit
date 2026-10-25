<?php

namespace App\Http\Requests\Assets;

use App\Enums\AssetStatus;
use App\Http\Requests\Concerns\ResolvesOwnFolders;
use App\Models\Asset;
use App\Models\Marketplace;
use App\Models\MarketplaceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Partial update of a work's base metadata and status: only the sent fields are validated and saved.
 * The info panel autosaves through it.
 */
class UpdateAssetRequest extends FormRequest
{
    use ResolvesOwnFolders;

    /** Longest keyword list a marketplace accepts (Shutterstock: 50, Adobe Stock: 49). */
    public const KEYWORDS_MAX = 50;

    /** Categories per marketplace when its rules do not say. */
    protected const CATEGORIES_MAX_DEFAULT = 1;

    protected ?Asset $asset = null;

    /**
     * Someone else's work is not found rather than forbidden: its existence is not revealed.
     */
    public function authorize(): bool
    {
        $this->asset();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'keywords' => ['sometimes', 'array', 'max:'.self::KEYWORDS_MAX],
            'keywords.*' => ['string', 'max:100'],
            'is_editorial' => ['sometimes', 'boolean'],
            'is_ai_generated' => ['sometimes', 'boolean'],
            // Keyed by marketplace code: {"adobe_stock": ["11"], "shutterstock": ["Nature", "Parks/Outdoor"]}.
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['array'],
            'categories.*.*' => ['string', 'max:100'],
            // Submitted is set by publishing (App\Services\Marketplaces\Publishing), not by the user.
            'status' => ['sometimes', Rule::enum(AssetStatus::class)->except(AssetStatus::Submitted)],
            // Folder ulid, null for Unsorted.
            'folder_id' => ['sometimes', 'nullable', 'string', $this->ownFolderRule()],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateCategories($validator),
            fn (Validator $validator) => $this->validateReady($validator),
        ];
    }

    /**
     * Validated fields as stored: the folder ulid becomes its id.
     *
     * @return array<string, mixed>
     */
    public function assetAttributes(): array
    {
        $data = $this->validated();

        if (array_key_exists('folder_id', $data)) {
            $data['folder_id'] = $this->ownFolderId($data['folder_id']);
        }

        return $data;
    }

    /**
     * The work being edited, from the current user's library only.
     */
    public function asset(): Asset
    {
        return $this->asset ??= $this->user()->ownAsset($this->route('asset'));
    }

    /**
     * Keywords are stored trimmed, lowercase and unique, in the order given.
     * Categories drop repeated values and marketplaces left without any.
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('keywords'))) {
            $this->merge([
                'keywords' => collect($this->input('keywords'))
                    ->filter(fn ($word) => is_string($word))
                    ->map(fn (string $word) => mb_strtolower(trim($word)))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }

        if (is_array($this->input('categories'))) {
            $this->merge([
                'categories' => collect($this->input('categories'))
                    ->map(fn ($values) => is_array($values) ? array_values(array_unique($values, SORT_REGULAR)) : $values)
                    ->reject(fn ($values) => $values === [])
                    ->all(),
            ]);
        }
    }

    /**
     * Categories only for the marketplaces the user is connected to, from their own lists,
     * applicable to the type of the work and within the marketplace's limit.
     */
    protected function validateCategories(Validator $validator): void
    {
        $categories = $this->input('categories');

        if (! is_array($categories) || ! $categories || $validator->errors()->has('categories*')) {
            return;
        }

        $asset = $this->asset();
        $marketplaces = Marketplace::query()
            ->whereIn('code', array_keys($categories))
            ->whereIn('id', $this->user()->marketplaceAccounts()->select('marketplace_id'))
            ->get()
            ->keyBy('code');

        foreach ($categories as $code => $values) {
            $field = "categories.{$code}";
            $marketplace = $marketplaces->get($code);

            if (! $marketplace) {
                $validator->errors()->add($field, __('library.validation.categories_marketplace'));

                continue;
            }

            $max = $marketplace->metadata_rules['categories_max'] ?? self::CATEGORIES_MAX_DEFAULT;

            if (count($values) > $max) {
                $validator->errors()->add($field, trans_choice('library.validation.categories_max', $max, ['max' => $max]));

                continue;
            }

            $known = MarketplaceCategory::query()
                ->where('marketplace_id', $marketplace->id)
                ->whereIn('external_id', $values)
                ->forMediaType($asset->type)
                ->count();

            if ($known !== count($values)) {
                $validator->errors()->add($field, __('library.validation.categories_unknown'));
            }
        }
    }

    /**
     * A ready work keeps a title and keywords: both the move to ready and edits of a ready work are checked.
     */
    protected function validateReady(Validator $validator): void
    {
        $asset = $this->asset();
        $status = $this->has('status') ? $this->input('status') : $asset->status->value;

        if ($status !== AssetStatus::Ready->value || $validator->errors()->hasAny(['status', 'title', 'keywords'])) {
            return;
        }

        $title = $this->has('title') ? $this->input('title') : $asset->title;
        $keywords = $this->has('keywords') ? $this->input('keywords') : $asset->keywords;

        if ($this->has('status') && $asset->status !== AssetStatus::Ready) {
            if (blank($title) || empty($keywords)) {
                $validator->errors()->add('status', __('library.validation.ready_requires_metadata'));
            }

            return;
        }

        if ($this->has('title') && blank($title)) {
            $validator->errors()->add('title', __('library.validation.ready_requires_title'));
        }

        if ($this->has('keywords') && empty($keywords)) {
            $validator->errors()->add('keywords', __('library.validation.ready_requires_keywords'));
        }
    }
}
