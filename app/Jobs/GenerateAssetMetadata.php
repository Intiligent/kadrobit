<?php

namespace App\Jobs;

use App\Enums\GenerationTrigger;
use App\Models\Asset;
use App\Services\Metadata\AssetMetadataService;
use App\Services\Metadata\GenerationLimitReached;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fills the empty metadata fields of a work from a generation: after upload (setting "Fill in metadata on
 * upload") or for several works the user selected. Fields the user edited or the file carried stay as they are.
 */
class GenerateAssetMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Covers three HTTP tries of OPENAI_METADATA_TIMEOUT (40 s) with their pauses; the worker runs with --timeout=150. */
    public int $timeout = 150;

    /** A work deleted before its turn needs nothing. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Asset $asset,
        public GenerationTrigger $trigger = GenerationTrigger::Upload,
    ) {
        // Its own queue: a large upload queues one generation per work, and emails on "default" must not wait.
        $this->onQueue('metadata');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("asset-metadata:{$this->asset->id}"))->releaseAfter(30)->expireAfter($this->timeout + 30)];
    }

    public function handle(AssetMetadataService $metadata): void
    {
        $asset = $this->asset->refresh();

        // Nothing left to fill: do not pay for a generation.
        if (collect(AssetMetadataService::FIELDS)->every(fn (string $field) => filled($asset->{$field}))) {
            return;
        }

        try {
            $generation = $metadata->generate($asset, $this->trigger);
        } catch (GenerationLimitReached) {
            Log::info("Metadata generation skipped for work {$asset->ulid}: monthly limit reached.");

            return;
        }

        $metadata->apply($asset, $generation);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning("Metadata generation failed for work {$this->asset->ulid}: {$e?->getMessage()}");
    }
}
