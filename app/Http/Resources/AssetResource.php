<?php

namespace App\Http\Resources;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
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
        $thumbnail = $this->file(AssetFileRole::Thumbnail) ?? $this->file(AssetFileRole::Preview);
        // The work as it is viewed in the panel: an image, or for a video its playback copy
        // (whose poster is the thumbnail). Its type follows the work's type, never a fallback.
        $preview = $this->type === MediaType::Video
            ? $this->file(AssetFileRole::VideoPreview)
            : $this->file(AssetFileRole::Preview) ?? $thumbnail;

        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'folder_id' => $this->folder?->ulid,
            'title' => $this->title ?? '',
            'description' => $this->description ?? '',
            'keywords' => $this->keywords,
            // An object even when empty, so the frontend can index it by marketplace code.
            'categories' => (object) ($this->categories ?? []),
            'is_editorial' => $this->is_editorial,
            'is_ai_generated' => $this->is_ai_generated,
            // Where each field came from: file | ai | user (App\Enums\MetadataSource); an object even when empty.
            'metadata_sources' => (object) ($this->metadata_sources ?? []),
            'original_filename' => $this->original_filename,
            'mime' => $original?->mime,
            'size_bytes' => $original?->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'captured_at' => $this->captured_at?->toIso8601String(),
            'location' => $this->location,
            'checksum' => $this->checksum,
            'thumbnail_url' => $thumbnail?->url(),
            'preview_url' => $preview?->url(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
