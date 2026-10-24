<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An array of secrets encrypted with the credentials key (see App\Support\CredentialsEncrypter).
 *
 * @implements CastsAttributes<array<string, string>|null, array<string, string>|null>
 */
class EncryptedCredentials implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return json_decode(app('credentials.encrypter')->decryptString($value), true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app('credentials.encrypter')->encryptString(json_encode($value));
    }
}
