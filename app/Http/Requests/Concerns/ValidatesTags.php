<?php

namespace App\Http\Requests\Concerns;

use App\Models\Tag;
use Illuminate\Validation\Rule;

/**
 * Tags in a request, `tags: [{ id }, { name }]`: the user's existing tags by id, new ones by name
 * (created by App\Services\Assets\AssetTags). Names are trimmed before they are checked.
 */
trait ValidatesTags
{
    /** Tags of one work, and tags added in one bulk request. */
    public const TAGS_MAX = 50;

    /**
     * Rules for the items of `tags`; the list itself is up to the request.
     *
     * @return array<string, mixed>
     */
    protected function tagItemRules(): array
    {
        return [
            'tags.*' => ['array:id,name'],
            'tags.*.id' => ['nullable', 'integer', Rule::exists('tags', 'id')->where('user_id', $this->user()->id)],
            'tags.*.name' => ['required_without:tags.*.id', 'nullable', 'string', 'max:'.Tag::NAME_MAX, 'regex:'.Tag::NAME_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function tagMessages(): array
    {
        return [
            'tags.max' => __('library.validation.tags_max', ['max' => self::TAGS_MAX]),
            'tags.*.id.exists' => __('library.validation.tag_unknown'),
            'tags.*.name.max' => __('library.validation.tag_name_max', ['max' => Tag::NAME_MAX]),
            'tags.*.name.regex' => __('library.validation.tag_name_chars'),
        ];
    }

    protected function trimTagNames(): void
    {
        if (! is_array($this->input('tags'))) {
            return;
        }

        $this->merge([
            'tags' => array_map(
                fn ($item) => is_array($item) && is_string($item['name'] ?? null) ? [...$item, 'name' => trim($item['name'])] : $item,
                $this->input('tags'),
            ),
        ]);
    }
}
