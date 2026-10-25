<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publishing to marketplaces
    |--------------------------------------------------------------------------
    |
    | Works are uploaded by App\Jobs\PublishListings, one session per marketplace
    | account, on the "publishing" queue. See docs/publishing.md.
    |
    */

    /*
    | Queue connection of the publishing jobs: its own, with a retry_after above
    | the job timeout, so a long video upload is not handed to a second worker.
    */

    'connection' => env('PUBLISHING_QUEUE_CONNECTION', 'publishing'),

    /*
    | Works in one send from the publish popover. The rest waits for the next one:
    | the popover lists a batch the user can still read through, and one send
    | never ties the account's uploads up for a whole day.
    */

    'batch_works' => (int) env('PUBLISHING_BATCH_WORKS', 100),

    /*
    | Seconds a session keeps taking new files; the rest goes on in a new job.
    | Below the job timeout by the time one large file may take.
    */

    'session_seconds' => (int) env('PUBLISHING_SESSION_SECONDS', 1800),

    /*
    | Seconds a publishing job may run (the worker's --timeout must not be lower).
    */

    'timeout' => (int) env('PUBLISHING_TIMEOUT', 3600),

    /*
    | Seconds one network operation of an FTP upload may wait (each write of a
    | large file), above the sign-in timeout (credentials.connect_timeout).
    */

    'transfer_timeout' => (int) env('PUBLISHING_TRANSFER_TIMEOUT', 120),

    /*
    | Tries to reach a marketplace server before the uploads fail with "connect",
    | and the pause before each next try, in seconds.
    */

    'connect_tries' => 3,

    'connect_backoff' => [60, 300],

    /*
    | Sign-ins of publishing sessions per account within the decay window. Kept
    | apart from the user's own checks in settings, and low, so a loop of failures
    | never gets the account locked on the marketplace.
    */

    'sign_ins' => 6,

    'sign_ins_decay_seconds' => 600,

    /*
    | Delivery by marketplace code: a class implementing
    | App\Services\Marketplaces\Delivery\Delivery. A marketplace without one
    | fails its uploads with "unsupported".
    */

    'deliveries' => [
        'shutterstock' => App\Services\Marketplaces\Delivery\ShutterstockDelivery::class,
    ],

];
