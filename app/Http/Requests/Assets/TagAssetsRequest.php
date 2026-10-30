<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\Concerns\ValidatesTags;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adds tags to several works at once, keeping the tags they already have.
 */
class TagAssetsRequest extends FormRequest
{
    use ValidatesTags;

    public const ASSETS_MAX = MoveAssetsRequest::ASSETS_MAX;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assets' => ['required', 'array', 'min:1', 'max:'.self::ASSETS_MAX],
            // Works of other users are skipped by the controller, not reported.
            'assets.*' => ['string', 'distinct'],
            'tags' => ['required', 'array', 'min:1', 'max:'.self::TAGS_MAX],
            ...$this->tagItemRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->tagMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->trimTagNames();
    }
}
