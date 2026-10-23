<?php

namespace App\Jobs;

use App\Enums\AssetFileRole;
use App\Enums\AssetUploadStatus;
use App\Enums\MediaType;
use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\AssetUpload;
use App\Services\Assets\PreviewGenerator;
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
 * 1. read the metadata from the staged file;
 * 2. make the previews of a photo (PreviewGenerator; videos and vectors come later);
 * 3. store the original on the private disk and the previews on the public one, at their final paths;
 * 4. create the work and its file rows, and mark the upload done, in one transaction;
 * 5. delete the staged file and the temp previews.
 *
 * If the commit fails, every stored file is deleted again. One job per file, in the upload's batch.
 */
class ProcessAssetUpload implements ShouldQueue
{
    use Batchable, Queueable;

    /** Progress reported after each step, for the per-file bar in the upload list. */
    public const PROGRESS_METADATA = 20;

    public const PROGRESS_PREVIEWS = 50;

    public const PROGRESS_STORED = 85;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public AssetUpload $upload) {}

    public function handle(PreviewGenerator $previews): void
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

        $local = $staging->path($upload->stagingPath());
        $type = StoreAssetUploadRequest::mediaType($upload->mime);
        $attributes = $type === MediaType::Photo ? $this->photoAttributes($local) : [];
        $upload->update(['progress' => self::PROGRESS_METADATA]);

        $copies = $type === MediaType::Photo ? $previews->forPhoto($local) : [];
        $upload->update(['progress' => self::PROGRESS_PREVIEWS]);

        $stored = [];

        try {
            $stored[] = $this->store($local, [
                'role' => AssetFileRole::Original,
                'mime' => $upload->mime,
                'size_bytes' => $upload->size_bytes,
                'width' => $attributes['width'] ?? null,
                'height' => $attributes['height'] ?? null,
                'checksum' => $upload->checksum,
            ], $this->extension());

            foreach ($copies as $copy) {
                $stored[] = $this->store($copy['path'], $copy, 'jpg', $copy['checksum']);
            }

            $upload->update(['progress' => self::PROGRESS_STORED]);

            DB::transaction(fn () => $this->createAsset($type, $attributes, $stored));
        } catch (UniqueConstraintViolationException) {
            $this->deleteStored($stored);
            $this->reject(__('library.upload.duplicate', ['name' => $upload->filename]));

            return;
        } catch (Throwable $e) {
            $this->deleteStored($stored);

            throw $e;
        } finally {
            $previews->discard($copies);
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
     * Writes a local file to the disk of its role and returns the attributes of its asset_files row.
     *
     * @param  array{role: AssetFileRole, mime: string, size_bytes: int, width: ?int, height: ?int, checksum: ?string}  $file
     * @return array<string, mixed>
     */
    protected function store(string $local, array $file, string $extension, ?string $hash = null): array
    {
        $disk = $file['role']->disk();
        $path = AssetFile::pathFor($this->upload->user->ulid, $this->upload->asset_ulid, $file['role'], $extension, $hash);
        $stream = fopen($local, 'rb');

        try {
            Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'role' => $file['role'],
            'disk' => $disk,
            'path' => $path,
            'mime' => $file['mime'],
            'size_bytes' => $file['size_bytes'],
            'width' => $file['width'],
            'height' => $file['height'],
            'checksum' => $file['checksum'],
        ];
    }

    /**
     * @param  list<array{disk: string, path: string}>  $stored
     */
    protected function deleteStored(array $stored): void
    {
        foreach ($stored as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    /**
     * @param  array{width?: int, height?: int, captured_at?: Carbon}  $attributes
     * @param  list<array<string, mixed>>  $files
     */
    protected function createAsset(MediaType $type, array $attributes, array $files): void
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

        $asset->files()->createMany($files);

        $upload->update(['status' => AssetUploadStatus::Done, 'progress' => 100, 'asset_id' => $asset->id, 'error' => null]);
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
