<?php

namespace App\Jobs;

use App\Enums\AssetFileRole;
use App\Enums\AssetUploadStatus;
use App\Enums\MediaType;
use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\AssetUpload;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Turns one uploaded file into a work. Everything that may fail is done on the staged local file
 * first; the file goes to R2 only after that, right before the work is committed, so the
 * assets/ prefix only ever holds files a work points to:
 *
 * 1. read the metadata from the staged file (previews and video probing will be added here);
 * 2. store the file at its final path on the private disk;
 * 3. create the work and its file row, and mark the upload done, in one transaction;
 * 4. delete the staged file.
 *
 * If the commit fails, the stored file is deleted again. One job per file, in the upload's batch.
 */
class ProcessAssetUpload implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public AssetUpload $upload) {}

    public function handle(): void
    {
        $upload = $this->upload;

        if ($upload->status !== AssetUploadStatus::Processing) {
            return;
        }

        if ($this->batch()?->cancelled()) {
            $this->reject(__('library.upload.cancelled'));

            return;
        }

        $staging = Storage::disk(AssetUpload::STAGING_DISK);

        if (! $staging->exists($upload->stagingPath())) {
            throw new RuntimeException("Upload {$upload->ulid} has no staged file.");
        }

        // A parallel upload of the same file may have finished first.
        if ($upload->user->assets()->where('checksum', $upload->checksum)->exists()) {
            $this->reject(__('library.upload.duplicate', ['name' => $upload->filename]));

            return;
        }

        $type = StoreAssetUploadRequest::mediaType($upload->mime);
        $attributes = $type === MediaType::Photo ? $this->photoAttributes($staging->path($upload->stagingPath())) : [];

        $disk = AssetFileRole::Original->disk();
        $path = AssetFile::pathFor($upload->user->ulid, $upload->asset_ulid, AssetFileRole::Original, $this->extension());

        $stream = $staging->readStream($upload->stagingPath());

        try {
            Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            DB::transaction(fn () => $this->createAsset($type, $attributes, $disk, $path));
        } catch (UniqueConstraintViolationException) {
            Storage::disk($disk)->delete($path);
            $this->reject(__('library.upload.duplicate', ['name' => $upload->filename]));

            return;
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }

        $upload->deleteStagedFile();
    }

    /**
     * After the last try: no work, and the staged file is not needed any more.
     */
    public function failed(?Throwable $e): void
    {
        $this->reject(__('library.upload.processing_failed'));
    }

    /**
     * @param  array{width?: int, height?: int, captured_at?: Carbon}  $attributes
     */
    protected function createAsset(MediaType $type, array $attributes, string $disk, string $path): void
    {
        $upload = $this->upload;

        $asset = new Asset([
            'folder_id' => $upload->folder_id,
            'type' => $type,
            'original_filename' => $upload->filename,
            'checksum' => $upload->checksum,
            ...$attributes,
        ]);
        $asset->ulid = $upload->asset_ulid;
        $asset->user()->associate($upload->user_id);
        $asset->save();

        $asset->files()->create([
            'role' => AssetFileRole::Original,
            'disk' => $disk,
            'path' => $path,
            'mime' => $upload->mime,
            'size_bytes' => $upload->size_bytes,
            'width' => $attributes['width'] ?? null,
            'height' => $attributes['height'] ?? null,
            'checksum' => $upload->checksum,
        ]);

        $upload->update(['status' => AssetUploadStatus::Done, 'asset_id' => $asset->id, 'error' => null]);
    }

    protected function reject(string $error): void
    {
        $this->upload->update(['status' => AssetUploadStatus::Failed, 'error' => $error]);
        $this->upload->deleteStagedFile();
    }

    /**
     * By the detected MIME type, not the name the file came with.
     */
    protected function extension(): string
    {
        return MimeTypes::getDefault()->getExtensions($this->upload->mime)[0]
            ?? (pathinfo($this->upload->filename, PATHINFO_EXTENSION) ?: 'bin');
    }

    /**
     * @return array{width?: int, height?: int, captured_at?: Carbon}
     */
    protected function photoAttributes(string $path): array
    {
        $attributes = [];
        $size = @getimagesize($path);

        if ($size) {
            $attributes['width'] = $size[0];
            $attributes['height'] = $size[1];
        }

        if ($this->upload->mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $taken = (@exif_read_data($path) ?: [])['DateTimeOriginal'] ?? null;

            try {
                if ($taken) {
                    $attributes['captured_at'] = Carbon::createFromFormat('Y:m:d H:i:s', $taken);
                }
            } catch (Throwable) {
                // An unreadable EXIF date is not worth failing the upload for.
            }
        }

        return $attributes;
    }
}
