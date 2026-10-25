<?php

namespace App\Services\Metadata\Contracts;

use App\Services\Metadata\GenerationException;
use App\Services\Metadata\GenerationRequest;
use App\Services\Metadata\GenerationResult;

/**
 * A provider that turns an image into metadata. Implementations only talk to their API:
 * the prompt and schema come from MetadataPrompt, limits are enforced by MetadataSanitizer.
 */
interface MetadataGenerator
{
    /**
     * @throws GenerationException
     */
    public function generate(GenerationRequest $request): GenerationResult;

    /**
     * Provider and model, e.g. "openai:gpt-6-luna": stored with every generation.
     */
    public function name(): string;
}
