<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A host name or IPv4 address that resolves only to public addresses. The server signs in to it,
 * so a private or reserved address would let a user probe Kadrobit's own network.
 * The address is checked once here; a DNS answer that changes before the sign-in is not covered.
 */
class PublicHostRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9.-]+$/', $value)) {
            $fail('settings.marketplaces.validation.host')->translate();

            return;
        }

        $addresses = filter_var($value, FILTER_VALIDATE_IP) ? [$value] : (gethostbynamel($value) ?: []);

        if ($addresses === []) {
            $fail('settings.marketplaces.validation.host_unknown')->translate();

            return;
        }

        foreach ($addresses as $address) {
            // Not private, loopback, link-local, carrier-grade NAT or documentation ranges.
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                $fail('settings.marketplaces.validation.host')->translate();

                return;
            }
        }
    }
}
