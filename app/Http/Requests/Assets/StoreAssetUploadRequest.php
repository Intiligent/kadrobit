<?php

namespace App\Http\Requests\Assets;

use App\Enums\MediaType;
use App\Models\Folder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StoreAssetUploadRequest extends FormRequest
{
    /** MIME types accepted as originals. */
    public const MIMES = ['image/jpeg', 'image/png', 'image/tiff', ...MediaType::VECTOR_MIMES, 'video/mp4', 'video/quicktime'];

    /** The same list for the browser's file picker. */
    public const ACCEPT = '.jpg,.jpeg,.png,.tif,.tiff,.svg,.eps,.mp4,.mov';

    protected ?Folder $folder = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:'.implode(',', self::MIMES), 'max:'.(self::maxSizeMb() * 1024)],
            // The folder open in the library: new works go into it. Checked in after().
            'folder_id' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => __('library.upload.too_large', ['max' => self::maxSizeMb()]),
            'file.mimetypes' => __('library.upload.unsupported'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('folder_id') && ! $this->folder()) {
                    $validator->errors()->add('folder_id', __('validation.exists', ['attribute' => 'folder']));
                }
            },
        ];
    }

    /**
     * The user's folder from `folder_id`, looked up once.
     */
    public function folder(): ?Folder
    {
        if (! $this->folder && $this->filled('folder_id')) {
            $this->folder = $this->user()->folders()->where('ulid', $this->input('folder_id'))->first();
        }

        return $this->folder;
    }

    /**
     * Largest file accepted, in megabytes: our own limit, or less when PHP would drop a bigger
     * request (upload_max_filesize, post_max_size). The browser checks files against it before
     * sending, so a too large file is reported as such instead of failing with a bare 413.
     */
    public static function maxSizeMb(): int
    {
        $php = intdiv(UploadedFile::getMaxFilesize(), 1024 * 1024);

        return max(1, min((int) config('assets.upload_max_mb'), $php ?: PHP_INT_MAX));
    }
}
