<?php

namespace App\Http\Requests\Assets;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadBatchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Files the client is about to send: the batch progress is counted against it.
            'files' => ['required', 'integer', 'min:1', 'max:'.config('assets.upload_batch_max_files')],
        ];
    }
}
