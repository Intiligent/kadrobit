<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Brings the submitted email to its stored form (User::normalizeEmail) before validation,
 * so lookups and uniqueness checks match regardless of case and spaces.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();
    }

    protected function normalizeEmail(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }
}
