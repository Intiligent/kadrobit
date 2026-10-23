<?php

namespace App\Http\Controllers;

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

        // The batch takes as many files as were announced when it was opened.
        if ($user->assetUploads()->where('batch_id', $found->id)->count() >= $found->options['files']) {
            throw new ExceptionWithMessage(__('library.upload.batch_full'), 409);
        }

        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());

        // Rejected before the file is kept; the job checks again for parallel uploads of the same file.
        if ($user->assets()->where('checksum', $checksum)->exists()) {
            throw new ExceptionWithMessage(__('library.upload.duplicate', ['name' => $file->getClientOriginalName()]), 409);
        }

        $upload = $user->assetUploads()->create([
            'batch_id' => $found->id,
            'folder_id' => $request->folder()?->id,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum' => $checksum,
        ]);

        // Without its job the upload would wait for nothing: undo both, so a retry starts clean.
        try {
            if ($file->storeAs(dirname($upload->stagingPath()), basename($upload->stagingPath()), AssetUpload::STAGING_DISK) === false) {
                throw new \RuntimeException("Could not stage upload {$upload->ulid}.");
            }

            $found->add(new ProcessAssetUpload($upload));
        } catch (Throwable $e) {
            $upload->deleteStagedFile();
            $upload->delete();

            throw $e;
        }

        return AssetUploadResource::make($upload->fresh('asset.files'))->response()->setStatusCode(201);
    }

    /**
     * The uploads of a batch that changed since `cursor` (the `cursor` of the previous poll).
     * The client follows each file's progress and the batch's from them.
     */
    public function showBatch(Request $request, string $batch): JsonResponse
    {
        $user = $request->user();
        $found = $this->batches->find($user, $batch);
        abort_unless($found, 404);

        $now = now();
        $since = $this->cursor($request->query('cursor'));

        $changed = $user->assetUploads()
            ->where('batch_id', $found->id)
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since->subSeconds(self::POLL_OVERLAP_SECONDS)))
            ->with(['asset.files', 'asset.folder:id,ulid'])
            ->orderBy('updated_at')
            ->get();

        return response()->json(['data' => [
            'id' => $found->id,
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
