<?php

namespace App\Http\Requests\Extension;

use App\Enums\ModerationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the browser extension found out on a marketplace's site, for several sent works at once. Only the sent
 * fields change; works of other users and works not on the marketplace yet are skipped, not reported.
 */
class ReportListingsRequest extends FormRequest
{
    public const LISTINGS_MAX = 500;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'listings' => ['required', 'array', 'min:1', 'max:'.self::LISTINGS_MAX],
            'listings.*.id' => ['required', 'integer', 'distinct'],
            'listings.*.external_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'listings.*.external_url' => ['sometimes', 'nullable', 'string', 'max:512', 'url:https'],
            'listings.*.moderation_status' => ['sometimes', 'nullable', Rule::enum(ModerationStatus::class)],
            // The extension wrote the metadata with this metadata_hash to the marketplace.
            'listings.*.metadata_hash' => ['sometimes', 'string', 'size:64'],
        ];
    }
}
