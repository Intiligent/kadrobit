<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Several works to fill from generations in the queue.
 */
class GenerateMetadataBatchRequest extends FormRequest
{
    public const ASSETS_MAX = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assets' => ['required', 'array', 'min:1', 'max:'.self::ASSETS_MAX],
            // Works of other users are skipped by the controller, not reported.
            'assets.*' => ['string', 'distinct'],
        ];
    }
}
