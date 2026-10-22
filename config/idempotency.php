<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Store for idempotency entries; null uses the default cache store. An
    | in-memory store (redis) keeps the check off the database: each mutating
    | request costs two cache operations.
    |
    */

    'store' => env('IDEMPOTENCY_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Replay Window
    |--------------------------------------------------------------------------
    |
    | How long a completed response is replayed for a repeated key, in seconds.
    | Client retries happen within seconds to minutes.
    |
    */

    'ttl' => (int) env('IDEMPOTENCY_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Processing Timeout
    |--------------------------------------------------------------------------
    |
    | How long a request is considered in progress, in seconds. A duplicate
    | arriving meanwhile gets 409. Expires on its own if the process dies.
    |
    */

    'processing_timeout' => (int) env('IDEMPOTENCY_PROCESSING_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Max Stored Body Size
    |--------------------------------------------------------------------------
    |
    | Larger responses are not stored: duplicates are still rejected while the
    | request runs, but a retry after it finished runs again.
    |
    */

    'max_body_size' => (int) env('IDEMPOTENCY_MAX_BODY_SIZE', 65536),

];
