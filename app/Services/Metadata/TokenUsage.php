<?php

namespace App\Services\Metadata;

/**
 * Tokens a generation was billed for, as the provider reported them.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input = 0,
        public int $cachedInput = 0,
        public int $output = 0,
        public int $reasoning = 0, // part of output
    ) {}

    /**
     * USD by the prices in config/metadata.php; null for a model without a price.
     */
    public function cost(string $model): ?float
    {
        // Not config("metadata.prices.{$model}"): model names have dots.
        $price = config('metadata.prices')[$model] ?? null;

        if (! $price) {
            return null;
        }

        [$input, $cached, $output] = $price;

        return (($this->input - $this->cachedInput) * $input + $this->cachedInput * $cached + $this->output * $output) / 1_000_000;
    }
}
