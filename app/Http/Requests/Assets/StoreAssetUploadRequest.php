<?php

namespace App\Http\Requests\Assets;

use App\Enums\MediaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetUploadRequest extends FormRequest
{
    /** MIME types accepted as originals. */
    public const MIMES = [
        'image/jpeg',
        'image/png',
        'image/tiff',
        'image/svg+xml',
        'application/postscript',
        'image/x-eps',
        'video/mp4',
        'video/quicktime',
    ];

    /** The same list for the browser's file picker. */
    public const ACCEPT = '.jpg,.jpeg,.png,.tif,.tiff,.svg,.eps,.mp4,.mov';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:'.implode(',', self::MIMES), 'max:'.(self::maxSizeMb() * 1024)],
            // The folder open in the library: new works go into it.
            'folder_id' => ['nullable', 'string', Rule::exists('folders', 'ulid')->where('user_id', $this->user()->id)],
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

    public static function maxSizeMb(): int
    {
        return config('assets.upload_max_mb');
    }

    /**
     * Media type by MIME. Illustrations cannot be told apart from photos by the file; the user changes it later.
     */
    public static function mediaType(string $mime): MediaType
    {
        return match (true) {
            str_starts_with($mime, 'video/') => MediaType::Video,
            in_array($mime, ['image/svg+xml', 'application/postscript', 'image/x-eps'], true) => MediaType::Vector,
            default => MediaType::Photo,
        };
    }
}
