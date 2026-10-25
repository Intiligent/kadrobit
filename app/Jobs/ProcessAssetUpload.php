<?php

namespace App\Jobs;

use App\Enums\AssetFileRole;
use App\Enums\AssetUploadStatus;
use App\Enums\GenerationTrigger;
use App\Enums\MediaType;
use App\Enums\MetadataSource;
use App\Enums\UserSettingKey;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\AssetUpload;
use App\Services\Assets\FileMetadataReader;
use App\Services\Assets\PreviewGenerator;
use App\Services\Assets\UnreadableMedia;
use App\Services\Assets\VideoTools;
use App\Services\Metadata\AssetMetadataService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Turns one uploaded file into a work. Everything that may fail is done on the staged local file
 * first; the file goes to R2 only after that, right before the work is committed, so the
 * assets/ prefix only ever holds files a work points to:
 *
 * 1. read the metadata from the staged file (EXIF of a photo, ffprobe of a video, and title, description and
 *    keywords the contributor embedded: FileMetadataReader);
 * 2. make the previews (PreviewGenerator): of a photo from itself, of a video from a frame plus a light
 *    H.264 copy (VideoTools), of a vector from its raster companion, which is stored too;
 * 3. store the original on the private disk and the previews on the public one, at their final paths;
 * 4. create the work and its file rows, and mark the upload done, in one transaction;
 * 5. delete the staged file and the temp previews;
 * 6. with the "Fill in metadata on upload" setting on, queue the generation of what is still empty
 *    (AssetMetadataService::queue(), GenerateAssetMetadata).
 *
 * If the commit fails, every stored file is deleted again. A file that cannot be read as what it claims
 * to be (UnreadableMedia) fails at once, without retries. One job per file, in the upload's batch.
 */
class ProcessAssetUpload implements ShouldQueue
{
    use Batchable, Queueable;

    /** Progress reported after each step, for the per-file bar in the upload list. */
    public const PROGRESS_METADATA = 20;

    public const PROGRESS_PREVIEWS = 50;

    /** Videos only: the playback copy is encoded between PROGRESS_PREVIEWS and this, reported as it goes. */
    public const PROGRESS_VIDEO_PREVIEW = 75;

    public const PROGRESS_STORED = 85;

    /** Seconds between progress writes while a video is encoded. */
    protected const PROGRESS_INTERVAL = 2;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * Large originals (TIFF, video) take a while. The queue's retry_after must stay above it
     * (DB_QUEUE_RETRY_AFTER), or a second worker picks the job up while it is still running.
     */
    public int $timeout = 300;

    public function __construct(public AssetUpload $upload) {}

    /**
     * Never two copies of the same upload at once: they would write and clean up the same paths.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->upload->ulid))->releaseAfter(30)->expireAfter($this->timeout + 30)];
    }

    public function handle(PreviewGenerator $previews, VideoTools $videos, FileMetadataReader $metadata): void
    {
        // Fresh from the database: an earlier run of this job may have finished it meanwhile.
        $upload = $this->upload->refresh();

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
        if ($upload->user->assets()->where('checksum', $upload->checksum)->where('ulid', '!=', $upload->asset_ulid)->exists()) {
            $this->reject(__('library.upload.duplicate', ['name' => $upload->filename]));

            return;
        }

        $local = $staging->path($upload->stagingPath());
        $type = MediaType::fromMime($upload->mime);
        $copies = [];
        $stored = [];

        try {
            [$attributes, $pixels, $copies] = match ($type) {
                MediaType::Video => $this->prepareVideo($local, $previews, $videos),
                MediaType::Vector => $this->prepareVector($local, $previews),
                default => $this->preparePhoto($local, $previews),
            };

            $attributes = [...$attributes, ...$this->embeddedMetadata($metadata->read($local, $upload->mime))];

            $stored[] = $this->store($local, [
                'role' => AssetFileRole::Original,
                'mime' => $upload->mime,
                'size_bytes' => $upload->size_bytes,
                // The file as stored; the work's own size is the upright one.
                'width' => $pixels[0] ?? null,
                'height' => $pixels[1] ?? null,
                'checksum' => $upload->checksum,
            ], $this->extension());

            foreach ($copies as $copy) {
                $stored[] = $this->store($copy['path'], $copy, $copy['extension'] ?? 'jpg', $copy['checksum']);
            }

            $upload->update(['progress' => self::PROGRESS_STORED]);

            DB::transaction(fn () => $this->createAsset($type, $attributes, $stored));
        } catch (UniqueConstraintViolationException) {
            // The work may exist already, from an earlier run of this very job: its files are ours, keep them.
            if ($upload->refresh()->status === AssetUploadStatus::Done) {
                $upload->deleteStagedFile();

                return;
            }

            $this->deleteStored($stored);
            $this->reject(__('library.upload.duplicate', ['name' => $upload->filename]));

            return;
        } catch (UnreadableMedia $e) {
            // The user's file, not a fault of the app: a warning, not an error report.
            Log::warning("Upload {$upload->ulid} ({$upload->mime}) cannot be read: {$e->getMessage()}");
            $this->deleteStored($stored);
            $this->reject(__('library.upload.processing_failed'));

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
     * EXIF size and date, and the thumbnail and preview.
     *
     * @return array{0: array<string, mixed>, 1: array{0: int, 1: int}|null, 2: list<array<string, mixed>>}
     */
    protected function preparePhoto(string $local, PreviewGenerator $previews): array
    {
        $pixels = $this->pixelSize($local);
        $attributes = $this->photoAttributes($local, $pixels);
        $this->upload->update(['progress' => self::PROGRESS_METADATA]);

        $copies = $previews->forPhoto($local);
        $this->upload->update(['progress' => self::PROGRESS_PREVIEWS]);

        return [$attributes, $pixels, $copies];
    }

    /**
     * ffprobe data, a thumbnail and preview from a frame, and the playback copy, whose encoding
     * moves the progress from PROGRESS_PREVIEWS to PROGRESS_VIDEO_PREVIEW.
     *
     * @return array{0: array<string, mixed>, 1: array{0: int, 1: int}, 2: list<array<string, mixed>>}
     */
    protected function prepareVideo(string $local, PreviewGenerator $previews, VideoTools $videos): array
    {
        $probe = $videos->probe($local);
        $attributes = Arr::only($probe, ['width', 'height', 'duration_ms', 'captured_at', 'media_info']);
        $this->upload->update(['progress' => self::PROGRESS_METADATA]);

        $frame = $videos->frame($local, $probe['duration_ms']);

        try {
            $copies = $previews->forPhoto($frame);
        } finally {
            @unlink($frame);
        }

        $this->upload->update(['progress' => self::PROGRESS_PREVIEWS]);

        try {
            $reported = microtime(true);
            $preview = $videos->preview($local, $probe['duration_ms'], function (float $done) use (&$reported) {
                if (microtime(true) - $reported >= self::PROGRESS_INTERVAL) {
                    $reported = microtime(true);
                    $span = self::PROGRESS_VIDEO_PREVIEW - self::PROGRESS_PREVIEWS;
                    $this->upload->update(['progress' => self::PROGRESS_PREVIEWS + (int) floor($span * $done)]);
                }
            });
        } catch (Throwable $e) {
            $previews->discard($copies);

            throw $e;
        }

        $copies[] = [
            'role' => AssetFileRole::VideoPreview,
            'path' => $preview['path'],
            'mime' => 'video/mp4',
            'extension' => 'mp4',
            'size_bytes' => filesize($preview['path']),
            'width' => $preview['width'],
            'height' => $preview['height'],
            'checksum' => hash_file('sha256', $preview['path']),
        ];
        $this->upload->update(['progress' => self::PROGRESS_VIDEO_PREVIEW]);

        return [$attributes, [$probe['stored_width'], $probe['stored_height']], $copies];
    }

    /**
     * The vector's own size, its raster companion, thumbnail and preview.
     *
     * @return array{0: array<string, mixed>, 1: array{0: int, 1: int}, 2: list<array<string, mixed>>}
     */
    protected function prepareVector(string $local, PreviewGenerator $previews): array
    {
        $this->upload->update(['progress' => self::PROGRESS_METADATA]);

        $vector = $previews->forVector($local, $this->upload->mime);
        $this->upload->update(['progress' => self::PROGRESS_PREVIEWS]);

        $size = [$vector['width'], $vector['height']];

        return [['width' => $size[0], 'height' => $size[1]], $size, $vector['copies']];
    }

    /**
     * What the file carries, marked as coming from the file so generation leaves it alone.
     *
     * @param  array{title: ?string, description: ?string, keywords: list<string>, is_ai_generated: bool}  $found
     * @return array<string, mixed>
     */
    protected function embeddedMetadata(array $found): array
    {
        $attributes = ['metadata_sources' => []];

        foreach (['title', 'description', 'keywords'] as $field) {
            if (filled($found[$field])) {
                $attributes[$field] = $found[$field];
                $attributes['metadata_sources'][$field] = MetadataSource::File->value;
            }
        }

        if ($found['is_ai_generated']) {
            $attributes['is_ai_generated'] = true;
        }

        return $attributes;
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
     * @param  array{width?: int, height?: int, captured_at?: Carbon, duration_ms?: int, media_info?: array}  $attributes
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

        if ($asset->user->setting(UserSettingKey::MetadataAutofillOnUpload) && app(AssetMetadataService::class)->available()) {
            app(AssetMetadataService::class)->queue($asset, GenerationTrigger::Upload);
        }

        $upload->update(['status' => AssetUploadStatus::Done, 'progress' => 100, 'asset_id' => $asset->id, 'error' => null]);
    }

    protected function reject(string $error): void
    {
        $this->upload->update(['status' => AssetUploadStatus::Failed, 'error' => $error]);
        $this->upload->deleteStagedFile();
    }

    /**
     * The file's own extension when it fits the detected MIME type (an .eps stays .eps, though
     * application/postscript lists .ai first), otherwise the type's usual one.
     */
    protected function extension(): string
    {
        $known = MimeTypes::getDefault()->getExtensions($this->upload->mime);
        $own = strtolower(pathinfo($this->upload->filename, PATHINFO_EXTENSION));

        if (in_array($own, $known, true)) {
            return $own;
        }

        return $known[0] ?? ($own ?: 'bin');
    }

    /**
     * Width and height of the image as stored in the file.
     *
     * @return array{0: int, 1: int}|null
     */
    protected function pixelSize(string $path): ?array
    {
        $size = @getimagesize($path);

        return $size ? [$size[0], $size[1]] : null;
    }

    /**
     * The work's size as it is seen, upright: EXIF orientations 5–8 turn the image by 90°,
     * like its previews. And the capture date from EXIF.
     *
     * @param  array{0: int, 1: int}|null  $pixels
     * @return array{width?: int, height?: int, captured_at?: Carbon}
     */
    protected function photoAttributes(string $path, ?array $pixels): array
    {
        $attributes = [];
        $exif = $this->upload->mime === 'image/jpeg' && function_exists('exif_read_data') ? (@exif_read_data($path) ?: []) : [];

        if ($pixels) {
            [$width, $height] = in_array((int) ($exif['Orientation'] ?? 1), [5, 6, 7, 8], true) ? [$pixels[1], $pixels[0]] : $pixels;
            $attributes['width'] = $width;
            $attributes['height'] = $height;
        }

        if ($exif) {
            $taken = $exif['DateTimeOriginal'] ?? null;

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
