<?php

namespace App\Services\Assets;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Tags sent with an edit of a work or with a bulk action: the user's existing tags by id (their ownership is checked
 * by the request, ValidatesTags), new ones by name. A name is found among the user's tags ignoring case, as the
 * unique index compares them, and created only when there is none.
 */
class AssetTags
{
    /**
     * Ids of the given tags, each once.
     *
     * @param  array<int, array{id?: int|null, name?: string|null}>  $items
     * @return array<int, int>
     */
    public function ids(User $user, array $items): array
    {
        $ids = array_map(fn (array $item) => $item['id'] ?? $this->findOrCreate($user, $item['name'])->id, $items);

        return array_values(array_unique($ids));
    }

    /**
     * The user's tag with this name, created if there is none. A parallel request creating the same tag at the same
     * moment makes the insert hit the unique index: it is rolled back to its own savepoint and that tag is taken.
     */
    public function findOrCreate(User $user, string $name): Tag
    {
        $find = fn () => $user->tags()->whereRaw('lower(name) = lower(?)', [$name])->first();

        try {
            return $find() ?? DB::transaction(fn () => $user->tags()->create(['name' => $name]));
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }
}
