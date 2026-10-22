<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UsernameRule implements ValidationRule
{
    /**
     * Letters, numbers, dots, underscores and dashes, 3 to 20 characters.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.-]{3,20}$/', $value)) {
            $fail('auth.validation.username')->translate();
        }
    }
}
