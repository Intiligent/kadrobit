<?php

namespace App\Http\Requests\Auth;

use App\Rules\UsernameRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Usernames are stored lowercase, so uniqueness is checked on the lowercased value.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => is_string($this->username) ? strtolower(trim($this->username)) : $this->username,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'username' => ['required', 'string', new UsernameRule, Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)],
        ];
    }
}
