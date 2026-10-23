<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\Concerns\ResolvesOwnFolders;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Puts several works into one folder, or takes them out of their folders (folder_id null).
 */
class MoveAssetsRequest extends FormRequest
{
    use ResolvesOwnFolders;

    public const ASSETS_MAX = 500;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assets' => ['required', 'array', 'min:1', 'max:'.self::ASSETS_MAX],
            // Works of other users are skipped by the controller, not reported.
            'assets.*' => ['string', 'distinct'],
            'folder_id' => ['present', 'nullable', 'string', $this->ownFolderRule()],
        ];
    }
}
