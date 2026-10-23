<?php

namespace App\Http\Requests\Folders;

use App\Http\Requests\Concerns\ValidatesFolders;
use App\Models\Folder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Rename, move into another folder or to the top level, reorder among siblings. Only the sent fields change.
 */
class UpdateFolderRequest extends FormRequest
{
    use ValidatesFolders;

    protected ?Folder $folder = null;

    /**
     * Someone else's folder is not found rather than forbidden: its existence is not revealed.
     */
    public function authorize(): bool
    {
        $this->folder();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.self::NAME_MAX],
            // Null: move to the top level.
            'parent_id' => ['sometimes', 'nullable', 'string', $this->ownFolderRule()],
            // Index among the new siblings; past the end means last.
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $folder = $this->folder();
                $parent = $this->movesParent() ? $this->parentFolder() : $folder->parent;

                if ($parent && $this->movesParent() && $this->wouldLoop($folder, $parent)) {
                    $validator->errors()->add('parent_id', __('library.folders.validation.into_itself'));

                    return;
                }

                $name = $this->has('name') ? $this->input('name') : $folder->name;

                if (($this->has('name') || $this->movesParent()) && $this->nameTaken($name, $parent?->id, $folder->id)) {
                    $validator->errors()->add($this->has('name') ? 'name' : 'parent_id', __('library.folders.validation.name_taken'));
                }
            },
        ];
    }

    /**
     * The folder being changed, from the current user's library only.
     */
    public function folder(): Folder
    {
        return $this->folder ??= $this->user()->folders()->with('parent')->where('ulid', $this->route('folder'))->firstOrFail();
    }

    public function movesParent(): bool
    {
        return $this->has('parent_id');
    }

    /**
     * A folder cannot go into itself or into one of its own subfolders.
     */
    public function wouldLoop(Folder $folder, Folder $parent): bool
    {
        $parents = $this->user()->folders()->pluck('parent_id', 'id');

        // Bounded by the number of folders, in case the stored tree already has a loop.
        for ($id = $parent->id, $steps = $parents->count(); $id && $steps >= 0; $id = $parents[$id] ?? null, $steps--) {
            if ($id === $folder->id) {
                return true;
            }
        }

        return false;
    }
}
