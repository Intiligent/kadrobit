<?php

namespace App\Services\Assets;

use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * Upload batches are Laravel job batches: the client opens one for a set of files and every uploaded
 * file adds its ProcessAssetUpload job to it.
 *
 * The batch starts empty and grows as files arrive, so Laravel's own finished_at and progress()
 * (set as soon as the queue drains) do not describe it: progress is counted from the batch's
 * asset_uploads against the number of files announced at the start, kept in the batch options
 * with the owner.
 */
class UploadBatches
{
    public function open(User $user, int $files): Batch
    {
        return Bus::batch([])
            ->name('asset-upload')
            ->allowFailures()
            ->withOption('user_id', $user->getKey())
            ->withOption('files', $files)
            ->dispatch();
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
