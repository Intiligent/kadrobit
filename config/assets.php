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

    /*
    |--------------------------------------------------------------------------
    | Videos
    |--------------------------------------------------------------------------
    |
    | ffprobe reads a video's size, length and date; ffmpeg takes the frame for
    | its thumbnail and preview and makes a light H.264 copy for playback in
    | the library (no sound, short side up to video_preview_height). Each run is
    | cut off after ffmpeg_timeout seconds, inside the job's own 300 s.
    |
    */

    'ffmpeg' => env('ASSETS_FFMPEG', 'ffmpeg'),

    'ffprobe' => env('ASSETS_FFPROBE', 'ffprobe'),

    'ffmpeg_threads' => (int) env('ASSETS_FFMPEG_THREADS', 2),

    'ffmpeg_timeout' => (int) env('ASSETS_FFMPEG_TIMEOUT', 240),

    'video_preview_height' => 720,

    /*
    |--------------------------------------------------------------------------
    | Vectors
    |--------------------------------------------------------------------------
    |
    | EPS (Ghostscript) and SVG (rsvg-convert from librsvg) are
    | rasterized into the JPEG some marketplaces take together with the vector,
    | at about this many megapixels (Adobe Stock used to ask for 15 MP); the
    | thumbnail and preview are made from it.
    |
    */

    'raster_companion_mp' => 16,

    'rsvg_convert' => env('ASSETS_RSVG_CONVERT', 'rsvg-convert'),

    'ghostscript' => env('ASSETS_GHOSTSCRIPT', 'gs'),

    // Seconds one rendering may take; Ghostscript can hang on a malformed file.
    'vector_timeout' => (int) env('ASSETS_VECTOR_TIMEOUT', 120),

];
