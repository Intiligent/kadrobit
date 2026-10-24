<?php

namespace App\Rules;

use App\Support\HostResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A server name that resolves only to public addresses (see App\Support\HostResolver). Gives the user
 * a field error up front; the connection check repeats the check at every sign-in.
 */
class PublicHostRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $resolver = app(HostResolver::class);

        if (! is_string($value) || ! $resolver->isValidName($value)) {
            $fail('settings.marketplaces.validation.host')->translate();

            return;
        }

        $addresses = $resolver->addresses($value);

        if ($addresses === []) {
            $fail('settings.marketplaces.validation.host_unknown')->translate();
        } elseif (array_filter($addresses, fn (string $address) => ! $resolver->isPublic($address)) !== []) {
            $fail('settings.marketplaces.validation.host')->translate();
        }
    }
}
