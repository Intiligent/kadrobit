<?php

namespace App\Services\Metadata;

/**
 * Metadata for one work, already within the limits (MetadataSanitizer).
 */
final readonly class GenerationResult
{
    /**
     * @param  list<string>  $keywords  Most important first
     * @param  array<string, list<string>>  $categories  external_ids by marketplace code
     * @param  list<string>  $fixes  What the sanitizer had to correct: a measure of how well the model follows the rules
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $keywords,
        public array $categories,
        public bool $looksEditorial,
        public string $model,
        public TokenUsage $usage,
        public int $durationMs,
        public array $fixes = [],
    ) {}

    public function cost(): ?float
    {
        return $this->usage->cost($this->model);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'categories' => $this->categories,
            'looks_editorial' => $this->looksEditorial,
            'model' => $this->model,
            'usage' => (array) $this->usage,
            'cost' => $this->cost(),
            'duration_ms' => $this->durationMs,
            'fixes' => $this->fixes,
        ];
    }
}
