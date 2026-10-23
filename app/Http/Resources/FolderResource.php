<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A folder of the flat list the explorer builds its tree from. The parent is referenced by ulid,
 * like the works' folder_id. Needs the `parent:id,ulid` relation loaded.
 *
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'parent_id' => $this->parent?->ulid,
            'name' => $this->name,
        ];
    }
}
