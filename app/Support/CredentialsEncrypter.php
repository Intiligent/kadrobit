<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * The encrypter for marketplace credentials (config/credentials.php). Separate from APP_KEY.
 */
class CredentialsEncrypter
{
    public static function make(array $config): Encrypter
    {
        if (blank($config['key'] ?? null)) {
            throw new RuntimeException('CREDENTIALS_KEY is not set. Generate one with `php artisan credentials:key`.');
        }

        $encrypter = new Encrypter(self::parse($config['key']), $config['cipher']);

        return $encrypter->previousKeys(array_map(self::parse(...), $config['previous_keys'] ?? []));
    }

    public static function generate(string $cipher): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey($cipher));
    }

    protected static function parse(string $key): string
    {
        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
