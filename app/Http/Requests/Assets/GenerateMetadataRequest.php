<?php

namespace App\Http\Requests\Assets;

use App\Services\Metadata\AssetMetadataService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fields to write from a generation (a new one or one from the history). Without `fields`, all of them.
 */
class GenerateMetadataRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fields' => ['sometimes', 'array', 'min:1'],
            'fields.*' => ['string', 'distinct', Rule::in(AssetMetadataService::FIELDS)],
        ];
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->validated('fields') ?? AssetMetadataService::FIELDS;
    }
}
