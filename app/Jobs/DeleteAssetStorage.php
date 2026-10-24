<?php

namespace App\Jobs;

use App\Enums\AssetFileRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes everything stored for a deleted work: its whole assets/{user}/{asset}/ prefix on the private
 * and the public bucket. By prefix, not by its file rows, so leftovers of failed processing go too.
 * Dispatched after the work's rows are committed as deleted; retried, as R2 can be briefly unavailable.
 */
class DeleteAssetStorage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $userUlid, public string $assetUlid) {}

    public function handle(): void
    {
        foreach ($this->disks() as $disk) {
            Storage::disk($disk)->deleteDirectory($this->prefix());
        }
    }

    /**
     * After the last try the files stay: logged, so they can be removed by hand.
     */
    public function failed(?Throwable $e): void
    {
        Log::error("Files of deleted work {$this->assetUlid} are still in storage under {$this->prefix()}.", [
            'disks' => $this->disks(),
            'error' => $e?->getMessage(),
        ]);
    }

    protected function prefix(): string
    {
        return "assets/{$this->userUlid}/{$this->assetUlid}";
    }

    /**
     * @return list<string>
     */
    protected function disks(): array
    {
        return array_values(array_unique([AssetFileRole::Original->disk(), AssetFileRole::Thumbnail->disk()]));
    }
}
