<?php

namespace App\Http\Resources;

use App\Models\AssetGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation from a work's history. Tokens and cost stay internal.
 *
 * @mixin AssetGeneration
 */
class AssetGenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger->value,
            'model' => $this->model,
            'title' => $this->result['title'] ?? '',
            'description' => $this->result['description'] ?? '',
            'keywords' => $this->result['keywords'] ?? [],
            'categories' => (object) ($this->result['categories'] ?? []),
            'looks_editorial' => (bool) ($this->result['looks_editorial'] ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
