<?php

namespace App\Services\Metadata;

use App\Services\Metadata\Contracts\MetadataGenerator;
use App\Services\Metadata\Drivers\OpenAiMetadataGenerator;
use InvalidArgumentException;

/**
 * Builds a generator by driver name from config/metadata.php. A new provider is a class implementing
 * MetadataGenerator, a `match` arm here and its block under `drivers`.
 */
class MetadataGeneratorFactory
{
    public function __construct(
        protected MetadataPrompt $prompt,
        protected MetadataSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  Replaces driver settings, e.g. ['model' => 'gpt-5.4-mini'] for comparisons
     */
    public function make(?string $driver = null, array $overrides = []): MetadataGenerator
    {
        $driver ??= config('metadata.driver');
        $config = array_replace(config("metadata.drivers.{$driver}") ?? [], $overrides);

        return match ($driver) {
            'openai' => new OpenAiMetadataGenerator($config, $this->prompt, $this->sanitizer),
            default => throw new InvalidArgumentException("Unknown metadata driver [{$driver}]."),
        };
    }
}
