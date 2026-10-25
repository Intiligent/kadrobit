<?php

namespace App\Jobs;

use App\Enums\GenerationTrigger;
use App\Models\Asset;
use App\Services\Metadata\AssetMetadataService;
use App\Services\Metadata\GenerationLimitReached;
use Carbon\CarbonInterface;
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

    /** Provider failures before giving up; a release while another job holds the work does not count. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** Covers three HTTP tries of OPENAI_METADATA_TIMEOUT (40 s) with their pauses; the worker runs with --timeout=150. */
    public int $timeout = 150;

    /** A work deleted for good before its turn needs nothing. */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  CarbonInterface|null  $markedAt  When AssetMetadataService::queue() marked the work (its token for finish())
     */
    public function __construct(
        public Asset $asset,
        public GenerationTrigger $trigger = GenerationTrigger::Upload,
        public ?CarbonInterface $markedAt = null,
    ) {
        $this->markedAt ??= now();

        // Its own queue: a large upload queues one generation per work, and emails on "default" must not wait.
        $this->onQueue('metadata');
    }

    /**
     * Limited by time, not tries: a job waiting for another one on the same work is released, and that must not
     * use up its tries. A large batch waits a while in the queue, hence the hours.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addHours(3);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // A second job for a work that is being generated waits for the first: it may have been asked for later
        // (the user selected the work again) and must not be lost.
        return [(new WithoutOverlapping("asset-metadata:{$this->asset->id}"))->releaseAfter(30)->expireAfter($this->timeout + 30)];
    }

    /**
     * Ends with finish() unless the provider failed: then the job is retried and the work stays "generating"
     * until a later try succeeds or failed() gives up.
     */
    public function handle(AssetMetadataService $metadata): void
    {
        $asset = $this->asset->refresh();

        try {
            // In the trash, or nothing it could write: do not pay for a generation.
            if (! $asset->trashed() && $metadata->needsFilling($asset)) {
                $metadata->apply($asset, $metadata->generate($asset, $this->trigger));
            }
        } catch (GenerationLimitReached) {
            Log::info("Metadata generation skipped for work {$asset->ulid}: monthly limit reached.");
        }

        $metadata->finish($asset, $this->markedAt);
    }

    public function failed(?Throwable $e): void
    {
        Log::warning("Metadata generation failed for work {$this->asset->ulid}: {$e?->getMessage()}");

        // A no-op for a work that is gone.
        app(AssetMetadataService::class)->finish($this->asset, $this->markedAt);
    }
}
