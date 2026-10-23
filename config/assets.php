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

];
