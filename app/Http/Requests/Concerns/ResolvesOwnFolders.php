<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Folders referenced by ulid in a request: only the current user's own, stored by id.
 */
trait ResolvesOwnFolders
{
    /**
     * A folder ulid from the current user's library.
     */
    protected function ownFolderRule(): Exists
    {
        return Rule::exists('folders', 'ulid')->where('user_id', $this->user()->id);
    }

    /**
     * Id of the user's folder with this ulid; null for no folder.
     */
    public function ownFolderId(?string $ulid): ?int
    {
        return $ulid ? $this->user()->folders()->where('ulid', $ulid)->value('id') : null;
    }
}
