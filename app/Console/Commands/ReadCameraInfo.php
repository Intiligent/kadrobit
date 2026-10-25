<?php

namespace App\Console\Commands;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Services\Assets\CameraInfoReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Reads the camera summary (media_info.camera, KB-46) of photos uploaded before it was read on upload:
 * downloads each original to a temp file and reads its EXIF. Photos without EXIF get an empty summary,
 * so a second run skips them too.
 */
class ReadCameraInfo extends Command
{
    protected $signature = 'assets:read-camera-info {--force : Read again photos that have a summary}';

    protected $description = 'Read the camera EXIF summary of photos uploaded before it was read on upload';

    public function handle(CameraInfoReader $reader): int
    {
        $query = Asset::query()->withTrashed()->where('type', MediaType::Photo)
            ->when(! $this->option('force'), fn ($query) => $query->whereRaw("not jsonb_exists(media_info, 'camera')"));

        $read = 0;
        $failed = 0;

        $query->with('files')->chunkById(100, function ($assets) use ($reader, &$read, &$failed) {
            foreach ($assets as $asset) {
                $original = $asset->file(AssetFileRole::Original);
                $temp = tempnam(sys_get_temp_dir(), 'exif');

                try {
                    $stream = $original?->storage()->readStream($original->path);

                    if (! $stream) {
                        throw new RuntimeException('original not found');
                    }

                    file_put_contents($temp, $stream);
                    $camera = $reader->read($temp);

                    // One field of media_info, so video details or later keys are kept.
                    DB::update("update assets set media_info = jsonb_set(media_info, '{camera}', ?::jsonb) where id = ?", [json_encode((object) $camera), $asset->id]);
                    $read++;
                } catch (Throwable $e) {
                    $this->warn("{$asset->ulid} ({$asset->original_filename}): {$e->getMessage()}");
                    $failed++;
                } finally {
                    @unlink($temp);
                }
            }
        });

        $this->info("Read {$read} photos".($failed ? ", {$failed} failed" : '').'.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
