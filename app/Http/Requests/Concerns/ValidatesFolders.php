<?php

namespace App\Http\Requests\Concerns;

use App\Models\Folder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared rules of folder requests: names are trimmed with inner spaces collapsed,
 * parents are the user's own folders, and sibling folders have different names, ignoring case.
 */
trait ValidatesFolders
{
    public const NAME_MAX = 100;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => preg_replace('/\s+/u', ' ', trim($this->input('name')))]);
        }
    }

    /**
     * A folder ulid from the current user's library.
     */
    protected function ownFolderRule(): Exists
    {
        return Rule::exists('folders', 'ulid')->where('user_id', $this->user()->id);
    }

    /**
     * The folder the request puts things into: null for the top level.
     */
    public function parentFolder(): ?Folder
    {
        $ulid = $this->input('parent_id');

        return $ulid ? $this->user()->folders()->where('ulid', $ulid)->first() : null;
    }

    protected function nameTaken(string $name, ?int $parentId, ?int $exceptId = null): bool
    {
        return $this->user()->folders()
            ->where('parent_id', $parentId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}
