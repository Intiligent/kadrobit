<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Largest original accepted per file, in megabytes. PHP (upload_max_filesize,
    | post_max_size) and the web server (nginx client_max_body_size) must allow
    | at least this much, see docs/library.md.
    |
    */

    'upload_max_mb' => (int) env('ASSETS_UPLOAD_MAX_MB', 200),

    /*
    | Most files one upload batch may announce.
    */

    'upload_batch_max_files' => 100,

    /*
    |--------------------------------------------------------------------------
    | Processing
    |--------------------------------------------------------------------------
    |
    | Uploads are processed on their own queue, so a large batch never holds up
    | emails and other jobs on "default". It runs several workers at once; each
    | Imagick then works on one thread (workers ≈ CPU cores) and within a memory
    | budget, beyond which it spills to disk instead of failing. See docs/library.md.
    |
    */

    'upload_queue' => env('ASSETS_UPLOAD_QUEUE', 'uploads'),

    'imagick_threads' => (int) env('ASSETS_IMAGICK_THREADS', 1),

    'imagick_memory_mb' => (int) env('ASSETS_IMAGICK_MEMORY_MB', 512),

];
