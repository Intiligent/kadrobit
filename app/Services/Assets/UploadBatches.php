<?php

namespace App\Services\Assets;

use App\Enums\AssetUploadStatus;
use App\Models\AssetUpload;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Upload batches are Laravel job batches: the client opens one for a set of files and every uploaded
 * file adds its ProcessAssetUpload job to it.
 *
 * The batch starts empty and grows as files arrive, so Laravel's own finished_at and progress()
 * (set as soon as the queue drains) do not describe it: the client follows its files through the
 * batch poll. The options keep the owner and the number of files announced at the start, which
 * caps the uploads the batch accepts.
 */
class UploadBatches
{
    public function open(User $user, int $files): Batch
    {
        return Bus::batch([])
            ->name('asset-upload')
            // Every job added later goes to the batch's queue.
            ->onQueue(config('assets.upload_queue'))
            ->allowFailures()
            ->withOption('user_id', $user->getKey())
            ->withOption('files', $files)
            ->dispatch();
    }

    /**
     * Batches of the user still at work, for the upload list after a page reload: each with its
     * uploads and how many announced files never reached the server (the page was left mid-upload).
     *
     * @return list<array{id: string, missing: int, uploads: Collection<int, AssetUpload>}>
     */
    public function active(User $user): array
    {
        $ids = $user->assetUploads()
            ->where('status', AssetUploadStatus::Processing)
            ->whereNotNull('batch_id')
            ->distinct()
            ->pluck('batch_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $uploads = $user->assetUploads()
            ->whereIn('batch_id', $ids)
            ->with(['asset.files', 'asset.folder:id,ulid'])
            ->orderBy('id')
            ->get()
            ->groupBy('batch_id');

        return $ids->map(fn (string $id) => [
            'id' => $id,
            'missing' => max(0, (int) ($this->find($user, $id)?->options['files'] ?? 0) - $uploads[$id]->count()),
            'uploads' => $uploads[$id],
        ])->values()->all();
    }

    /**
     * The batch, only when it is an upload batch of this user.
     */
    public function find(User $user, string $id): ?Batch
    {
        $batch = Bus::findBatch($id);

        if (! $batch || $batch->name !== 'asset-upload' || ($batch->options['user_id'] ?? null) !== $user->getKey()) {
            return null;
        }

        return $batch;
    }
}
