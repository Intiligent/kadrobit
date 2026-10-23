<?php

namespace App\Http\Controllers;

use App\Enums\AssetUploadStatus;
use App\Exceptions\ExceptionWithMessage;
use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Http\Requests\Assets\StoreUploadBatchRequest;
use App\Http\Resources\AssetUploadResource;
use App\Jobs\ProcessAssetUpload;
use App\Models\AssetUpload;
use App\Services\Assets\UploadBatches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Uploads in batches: open a batch for a set of files, send the files one by one, poll the batch.
 * A file becomes a work only once its ProcessAssetUpload job has succeeded.
 */
class AssetUploadController extends Controller
{
    /**
     * Polls look back this far past their cursor: a job may commit a status a moment after
     * a poll read the newer rows of other jobs. The client ignores uploads it has settled already.
     */
    protected const POLL_OVERLAP_SECONDS = 10;

    public function __construct(protected UploadBatches $batches) {}

    public function storeBatch(StoreUploadBatchRequest $request): JsonResponse
    {
        $batch = $this->batches->open($request->user(), $request->integer('files'));

        return response()->json(['data' => ['id' => $batch->id]], 201);
    }

    /**
     * Receive one file of a batch: it waits on the local staging disk, and its job joins the batch.
     */
    public function store(StoreAssetUploadRequest $request, string $batch): JsonResponse
    {
        $user = $request->user();
        $found = $this->batches->find($user, $batch);
        abort_unless($found, 404);

        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());

        // Rejected before the file is kept; the job checks again for parallel uploads of the same file.
        if ($user->assets()->where('checksum', $checksum)->exists()) {
            throw new ExceptionWithMessage(__('library.upload.duplicate', ['name' => $file->getClientOriginalName()]), 409);
        }

        $upload = $user->assetUploads()->create([
            'batch_id' => $found->id,
            'folder_id' => $request->filled('folder_id')
                ? $user->folders()->where('ulid', $request->input('folder_id'))->value('id')
                : null,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum' => $checksum,
        ]);

        try {
            if ($file->storeAs(dirname($upload->stagingPath()), basename($upload->stagingPath()), AssetUpload::STAGING_DISK) === false) {
                throw new \RuntimeException("Could not stage upload {$upload->ulid}.");
            }
        } catch (Throwable $e) {
            $upload->delete();

            throw $e;
        }

        $found->add(new ProcessAssetUpload($upload));

        return AssetUploadResource::make($upload->fresh('asset.files'))->response()->setStatusCode(201);
    }

    /**
     * Progress of a batch, and the uploads that changed since `cursor` (the `cursor` of the previous poll).
     */
    public function showBatch(Request $request, string $batch): JsonResponse
    {
        $user = $request->user();
        $found = $this->batches->find($user, $batch);
        abort_unless($found, 404);

        $now = now();
        $since = $this->cursor($request->query('cursor'));

        $uploads = $user->assetUploads()->where('batch_id', $found->id);
        $counts = (clone $uploads)->toBase()
            ->selectRaw('count(*) filter (where status = ?) as done, count(*) filter (where status = ?) as failed', [
                AssetUploadStatus::Done->value, AssetUploadStatus::Failed->value,
            ])
            ->first();

        $changed = $uploads
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since->subSeconds(self::POLL_OVERLAP_SECONDS)))
            ->with(['asset.files', 'asset.folder:id,ulid'])
            ->orderBy('updated_at')
            ->get();

        return response()->json(['data' => [
            'id' => $found->id,
            'files' => $found->options['files'],
            'done' => (int) $counts->done,
            'failed' => (int) $counts->failed,
            'cursor' => $now->toIso8601ZuluString('microsecond'),
            'uploads' => AssetUploadResource::collection($changed)->resolve(),
        ]]);
    }

    protected function cursor(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
