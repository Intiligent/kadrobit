<?php

namespace App\Http\Resources;

use App\Enums\AssetFileRole;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $original = $this->file(AssetFileRole::Original);
        $thumbnail = $this->file(AssetFileRole::Thumbnail);
        $preview = $this->file(AssetFileRole::Preview);

        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'folder_id' => $this->folder?->ulid,
            'title' => $this->title ?? '',
            'description' => $this->description ?? '',
            'keywords' => $this->keywords,
            'is_editorial' => $this->is_editorial,
            'is_ai_generated' => $this->is_ai_generated,
            'original_filename' => $this->original_filename,
            'mime' => $original?->mime,
            'size_bytes' => $original?->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'location' => $this->location,
            'checksum' => $this->checksum,
            'thumbnail_url' => ($thumbnail ?? $preview)?->url(),
            'preview_url' => ($preview ?? $thumbnail)?->url(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
