<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Marketplace credentials
    |--------------------------------------------------------------------------
    |
    | Upload passwords are encrypted with their own key, not APP_KEY, so a copy
    | of the database together with the web app's .env does not reveal them when
    | this key is kept elsewhere (deployment secrets, KMS). Generate one with
    | `php artisan credentials:key`. To rotate: move the old key into
    | CREDENTIALS_PREVIOUS_KEYS, set the new one, run `php artisan credentials:rotate`.
    | See docs/settings.md.
    |
    */

    'key' => env('CREDENTIALS_KEY'),

    'previous_keys' => array_filter(explode(',', (string) env('CREDENTIALS_PREVIOUS_KEYS', ''))),

    'cipher' => 'aes-256-gcm',

    /*
    | Seconds to wait for a marketplace FTP/SFTP server during a connection check.
    */

    'connect_timeout' => (int) env('CREDENTIALS_CONNECT_TIMEOUT', 10),

];
