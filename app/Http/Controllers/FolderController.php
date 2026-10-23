<?php

namespace App\Http\Controllers;

use App\Http\Requests\Folders\StoreFolderRequest;
use App\Http\Requests\Folders\UpdateFolderRequest;
use App\Http\Resources\FolderResource;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The library's folder tree. Every change answers with the whole flat list in tree order,
 * so the explorer never has to replay positions of the siblings on its own.
 */
class FolderController extends Controller
{
    /**
     * A new folder goes last among its siblings.
     */
    public function store(StoreFolderRequest $request): JsonResponse
    {
        $user = $request->user();
        $parent = $request->parentFolder();

        $folder = DB::transaction(function () use ($user, $parent, $request) {
            $this->lockTree($user);

            return $user->folders()->create([
                'parent_id' => $parent?->id,
                'name' => $request->input('name'),
                'position' => ($user->folders()->where('parent_id', $parent?->id)->max('position') ?? -1) + 1,
            ]);
        });

        return $this->list($user)->additional(['meta' => ['id' => $folder->ulid]])->response()->setStatusCode(201);
    }

    public function update(UpdateFolderRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $folder = $request->folder();

        DB::transaction(function () use ($user, $folder, $request) {
            $this->lockTree($user);

            if ($request->has('name')) {
                $folder->name = $request->input('name');
            }

            // Checked again under the lock: a parallel move could have changed the tree since validation.
            if (($parent = $request->parentFolder()) && $request->movesParent() && $request->wouldLoop($folder, $parent)) {
                throw ValidationException::withMessages(['parent_id' => __('library.folders.validation.into_itself')]);
            }

            if ($request->movesParent() || $request->has('position')) {
                $parentId = $request->movesParent() ? $request->parentFolder()?->id : $folder->parent_id;
                $this->place($user, $folder, $parentId, $request->integer('position', PHP_INT_MAX));
            }

            $folder->save();
        });

        return $this->list($user);
    }

    /**
     * Subfolders go with the folder; works stay in the library without a folder (foreign keys).
     */
    public function destroy(Request $request, string $folder): AnonymousResourceCollection
    {
        $user = $request->user();

        $user->folders()->where('ulid', $folder)->firstOrFail()->delete();

        return $this->list($user);
    }

    /**
     * Puts the folder at `position` among the siblings under `parentId` and renumbers them 0…n.
     */
    protected function place(User $user, Folder $folder, ?int $parentId, int $position): void
    {
        $siblings = $user->folders()
            ->where('parent_id', $parentId)
            ->whereKeyNot($folder->id)
            ->ordered()
            ->get();

        $siblings->splice(min($position, $siblings->count()), 0, [$folder]);

        foreach ($siblings->values() as $index => $sibling) {
            $sibling->parent_id = $parentId;
            $sibling->position = $index;

            // The moved folder is saved by the caller, together with its new name.
            if ($sibling->isNot($folder)) {
                $sibling->save();
            }
        }
    }

    /**
     * Serializes changes of one user's tree: positions and the loop check read the other folders.
     */
    protected function lockTree(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    protected function list(User $user): AnonymousResourceCollection
    {
        return FolderResource::collection($user->folders()->with('parent:id,ulid')->ordered()->get());
    }
}
