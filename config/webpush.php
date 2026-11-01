<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID
    |--------------------------------------------------------------------------
    |
    | The key pair push services check our messages with: generated once with
    | `php artisan webpush:vapid`, never changed afterwards (every browser's
    | subscription is tied to the public key). The subject is a contact URL
    | or mailto: the push services may use. See docs/notifications.md.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    'database_connection' => env('DB_CONNECTION', 'pgsql'),

    // Guzzle options of the requests to the push services.
    'client_options' => [],

    // Pads messages to one length, so their size does not tell what they say.
    'automatic_padding' => true,

];
