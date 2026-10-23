<?php

namespace App\Http\Resources;

use App\Enums\AssetUploadStatus;
use App\Models\AssetUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An uploaded file on its way to becoming a work. `asset` is the work, present once the upload is done.
 *
 * @mixin AssetUpload
 */
class AssetUploadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'batch_id' => $this->batch_id,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'status' => $this->status->value,
            // Processing steps done, 0–100; the transfer before it is measured by the browser.
            'progress' => $this->progress,
            'error' => $this->error,
            'asset' => $this->status === AssetUploadStatus::Done && $this->asset
                ? AssetResource::make($this->asset)->resolve($request)
                : null,
        ];
    }
}
