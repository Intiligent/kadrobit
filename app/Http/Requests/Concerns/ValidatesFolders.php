<?php

namespace App\Http\Requests\Concerns;

use App\Models\Folder;

/**
 * Shared rules of folder requests: names are trimmed with inner spaces collapsed,
 * parents are the user's own folders, and sibling folders have different names, ignoring case.
 */
trait ValidatesFolders
{
    use ResolvesOwnFolders;

    public const NAME_MAX = 100;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim($this->input('name')))]);
        }
    }

    /**
     * The folder the request puts things into: null for the top level.
     */
    public function parentFolder(): ?Folder
    {
        $ulid = $this->input('parent_id');

        return $ulid ? $this->user()->folders()->where('ulid', $ulid)->first() : null;
    }

    /**
     * Compared by the database's lower(), like the unique index, so the check and the index always agree.
     * Also called under the tree lock, where a parallel change may have taken the name since validation.
     */
    public function nameTaken(string $name, ?int $parentId, ?int $exceptId = null): bool
    {
        return $this->user()->folders()
            ->where('parent_id', $parentId)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
