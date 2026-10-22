<?php

namespace App\Http\Requests\Auth;

use App\Services\Auth\EmailVerificationService;
use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailCodeRequest extends FormRequest
{
    /**
     * Spaces and dashes are allowed when the code is pasted ("123 456").
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => preg_replace('/[\s-]+/', '', $this->input('code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:'.EmailVerificationService::CODE_LENGTH],
        ];
    }
}
