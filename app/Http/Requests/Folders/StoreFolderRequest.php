<?php

namespace App\Http\Requests\Folders;

use App\Http\Requests\Concerns\ValidatesFolders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFolderRequest extends FormRequest
{
    use ValidatesFolders;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            // Null or missing: a top-level folder.
            'parent_id' => ['nullable', 'string', $this->ownFolderRule()],
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

                if ($this->nameTaken($this->input('name'), $this->parentFolder()?->id)) {
                    $validator->errors()->add('name', __('library.folders.validation.name_taken'));
                }
            },
        ];
    }
}
