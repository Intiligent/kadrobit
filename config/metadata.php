<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Metadata generation
    |--------------------------------------------------------------------------
    |
    | Title, description, keywords and categories of a work, generated from its
    | preview (KB-35). The driver is the provider; switching providers is a
    | config change: each driver implements App\Services\Metadata\Contracts\MetadataGenerator
    | and is registered in MetadataGeneratorFactory.
    |
    */

    'driver' => env('METADATA_DRIVER', 'openai'),

    // Longest side of the image sent to the model. Image tokens grow with the area.
    'image_size' => (int) env('METADATA_IMAGE_SIZE', 1024),

    // Paid generations per user and calendar month, automatic and manual together.
    'monthly_limit' => (int) env('METADATA_MONTHLY_LIMIT', 1000),

    // Limits of the generated texts, the strictest among the marketplaces (see docs/metadata-generation.md).
    'limits' => [
        'title' => 70,
        'description' => 150,
        'keywords' => 49,
    ],

    'drivers' => [
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('OPENAI_METADATA_MODEL', 'gpt-6-luna'),
            // Empty for models without reasoning; reasoning tokens are billed as output.
            'reasoning_effort' => env('OPENAI_METADATA_REASONING_EFFORT', 'low'),
            // low | high | auto: "low" sends a fixed small version of the image.
            'image_detail' => env('OPENAI_METADATA_IMAGE_DETAIL', 'auto'),
            'max_output_tokens' => (int) env('OPENAI_METADATA_MAX_OUTPUT_TOKENS', 4000),
            'timeout' => (int) env('OPENAI_METADATA_TIMEOUT', 40),
            // Pause before a retry after 429 or 5xx, times the attempt number.
            'retry_delay_ms' => (int) env('OPENAI_METADATA_RETRY_DELAY_MS', 2000),
        ],
    ],

    // USD per 1M tokens: input, cached input, output. Only for cost reports; checked 24.10.2026
    // at https://developers.openai.com/api/docs/pricing.
    'prices' => [
        'gpt-6-luna' => [0.10, 0.01, 0.50],
        'gpt-5.6-luna' => [0.20, 0.02, 1.20],
        'gpt-5.4-nano' => [0.20, 0.02, 1.25],
        'gpt-5.4-mini' => [0.75, 0.075, 4.50],
        'gpt-6.1-sol' => [2.00, 0.10, 10.00],
    ],

    // Models compared by `php artisan metadata:evaluate` when none are given.
    'evaluation_models' => ['gpt-6-luna', 'gpt-5.6-luna', 'gpt-5.4-mini', 'gpt-6.1-sol'],

];
