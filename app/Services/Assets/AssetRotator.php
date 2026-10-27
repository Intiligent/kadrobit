<?php

namespace App\Services\Assets;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MediaType;
use App\Exceptions\ExceptionWithMessage;
use App\Models\Asset;
use App\Models\AssetFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickException;
use RuntimeException;
use Throwable;

/**
 * Turns a raster work by a multiple of 90° clockwise and stores the result in place of its original,
 * which is what goes to marketplaces. The file is re-encoded once by Imagick at its own JPEG quality;
 * EXIF, IPTC, XMP and the colour profile stay, the EXIF orientation is applied first and reset to normal.
 * The thumbnail and the preview are made again from the turned file.
 *
 * Every new file goes to a new path (a content hash in the name, AssetFile::pathFor()), the rows are switched
 * in one transaction, and the old files are deleted only after it: a failure at any step leaves the work as it was.
 * The work's checksum stays the one of the uploaded file, so uploading that file again is still a duplicate.
 */
class AssetRotator
{
    /** Types whose original is a raster image Imagick can turn. */
    public const TYPES = [MediaType::Photo, MediaType::Illustration];

    /** Statuses a work can be turned in: a submitted work is on the marketplaces as it was sent. */
    public const STATUSES = [AssetStatus::Draft, AssetStatus::Ready];

    /** Seconds a rotation may hold the work's lock: a large TIFF is read, turned and written twice. */
    protected const LOCK_SECONDS = 300;

    public function __construct(protected PreviewGenerator $previews) {}

    /**
     * @param  int  $degrees  90, 180 or 270, clockwise
     */
    public function rotate(Asset $asset, int $degrees): Asset
    {
        $lock = Cache::lock("asset-rotate:{$asset->ulid}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new ExceptionWithMessage(__('library.tools.rotate_busy'), 409);
        }

        try {
            return $this->rotateLocked($asset, $degrees);
        } finally {
            $lock->release();
        }
    }

    /**
     * Why the work cannot be turned, or null when it can.
     */
    public function refusal(Asset $asset): ?string
    {
        return match (true) {
            ! in_array($asset->type, self::TYPES, true) => __('library.tools.rotate_unsupported'),
            ! in_array($asset->status, self::STATUSES, true) => __('library.tools.rotate_not_editable'),
            // Its file is about to be or being uploaded: it must not change under the upload.
            $asset->listings()->whereIn('status', [ListingStatus::Queued, ListingStatus::Sending])->exists() => __('library.tools.rotate_publishing'),
            default => null,
        };
    }

    protected function rotateLocked(Asset $asset, int $degrees): Asset
    {
        $this->assertAllowed($asset->refresh());

        $original = $asset->file(AssetFileRole::Original) ?? throw new RuntimeException("Work {$asset->ulid} has no original.");
        $source = $this->download($original);
        $turned = null;
        $copies = [];
        $stored = [];

        try {
            $turned = $this->turn($source, $degrees);
            $copies = $this->previews->forPhoto($turned['path']);

            $stored[AssetFileRole::Original->value] = $this->store($asset, $turned['path'], AssetFileRole::Original, pathinfo($original->path, PATHINFO_EXTENSION), [
                'mime' => $original->mime,
                'width' => $turned['width'],
                'height' => $turned['height'],
            ]);

            foreach ($copies as $copy) {
                $stored[$copy['role']->value] = $this->store($asset, $copy['path'], $copy['role'], 'jpg', $copy);
            }

            $replaced = DB::transaction(fn () => $this->switchFiles($asset, $stored, $turned));
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                Storage::disk($file['disk'])->delete($file['path']);
            }

            throw $e;
        } finally {
            @unlink($source);

            if ($turned) {
                @unlink($turned['path']);
            }

            $this->previews->discard($copies);
        }

        // The rows point to the new files now; a file left behind here is only wasted space.
        foreach ($replaced as $file) {
            Storage::disk($file->disk)->delete($file->path);
        }

        return $asset->refresh();
    }

    protected function assertAllowed(Asset $asset): void
    {
        if ($refusal = $this->refusal($asset)) {
            throw new ExceptionWithMessage($refusal, 409);
        }
    }

    /**
     * The original from storage into a temp file, with its own extension: Imagick tells formats by it too.
     */
    protected function download(AssetFile $original): string
    {
        $reserved = tempnam(sys_get_temp_dir(), 'rotate');
        $path = $reserved.'.'.pathinfo($original->path, PATHINFO_EXTENSION);
        rename($reserved, $path);
        $stream = $original->storage()->readStream($original->path) ?? throw new RuntimeException("Original {$original->path} cannot be read.");

        try {
            if (file_put_contents($path, $stream) === false) {
                throw new RuntimeException("Original {$original->path} cannot be copied to {$path}.");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    /**
     * The turned image in the same format, every page of a TIFF included.
     *
     * @return array{path: string, width: int, height: int}
     */
    protected function turn(string $source, int $degrees): array
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('The imagick PHP extension is required for rotation.');
        }

        // Several workers run side by side: the same thread and memory budget as the previews.
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, max(1, config('assets.imagick_threads')));
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, config('assets.imagick_memory_mb') * 1024 * 1024);

        $image = new Imagick;
        $path = preg_replace('/\.(\w+)$/', '.turned.$1', $source);

        try {
            $image->readImage($source);

            foreach ($image as $frame) {
                // Upright as it is seen first; Imagick writes the orientation back into EXIF as normal.
                $frame->autoOrient();
                $frame->rotateImage('none', $degrees);
                $frame->setImagePage(0, 0, 0, 0);
            }

            $image->setFirstIterator();
            $image->writeImages($path, true);

            return ['path' => $path, 'width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
        } catch (ImagickException $e) {
            @unlink($path);

            throw new UnreadableMedia($e->getMessage(), previous: $e);
        } finally {
            $image->clear();
        }
    }

    /**
     * Writes a local file to the disk of its role, under a name with its content hash.
     *
     * @param  array{mime: string, width: int, height: int}  $file
     * @return array{role: AssetFileRole, disk: string, path: string, mime: string, size_bytes: int, width: int, height: int, checksum: string}
     */
    protected function store(Asset $asset, string $local, AssetFileRole $role, string $extension, array $file): array
    {
        $checksum = hash_file('sha256', $local);
        $disk = $role->disk();
        $path = AssetFile::pathFor($asset->user->ulid, $asset->ulid, $role, $extension, $checksum);
        $stream = fopen($local, 'rb');

        try {
            Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'role' => $role,
            'disk' => $disk,
            'path' => $path,
            'mime' => $file['mime'],
            'size_bytes' => filesize($local),
            'width' => $file['width'],
            'height' => $file['height'],
            'checksum' => $checksum,
        ];
    }

    /**
     * Points the work's rows to the new files and returns the rows of the files they replaced.
     *
     * @param  array<string, array<string, mixed>>  $stored  by role
     * @param  array{width: int, height: int}  $turned
     * @return list<AssetFile>
     */
    protected function switchFiles(Asset $asset, array $stored, array $turned): array
    {
        // Checked again under the row lock: a publish may have queued the work meanwhile.
        $work = $asset->user->ownAsset($asset->ulid, lock: true);
        $this->assertAllowed($work);

        $replaced = [];

        foreach ($stored as $file) {
            $row = $work->files()->where('role', $file['role'])->first();

            if ($row) {
                $replaced[] = $row->replicate();
                $row->update($file);
            } else {
                $work->files()->create($file);
            }
        }

        // Touched even when a half turn keeps the size: the library takes the newer version by updated_at.
        $work->fill(['width' => $turned['width'], 'height' => $turned['height']])->touch();

        return $replaced;
    }
}
