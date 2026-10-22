<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnverifiedEmailRequest extends FormRequest
{
    use NormalizesEmail;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class, 'email')->ignore($this->user()->getKey()),
            ],
        ];
    }

    /**
     * A neutral message: "already registered" would let anyone check which emails have accounts.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('verification.errors.email_unavailable'),
        ];
    }
}
