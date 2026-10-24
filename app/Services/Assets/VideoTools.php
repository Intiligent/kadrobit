<?php

namespace App\Services\Assets;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * ffprobe and ffmpeg for uploaded videos: what the video is, a still frame for its thumbnail and preview,
 * and a light H.264 copy for playback in the library. Works on the local staged file; every output
 * goes to a temp file the caller stores and deletes.
 */
class VideoTools
{
    /**
     * Display size (turned by the rotation the player applies), length, capture date and a short
     * summary of the streams for assets.media_info.
     *
     * @return array{width: int, height: int, stored_width: int, stored_height: int, duration_ms: int, captured_at: ?Carbon, media_info: array<string, mixed>}
     */
    public function probe(string $path): array
    {
        $result = Process::timeout(60)->run([
            config('assets.ffprobe'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);

        $info = $result->successful() ? json_decode($result->output(), true) : null;
        $streams = collect($info['streams'] ?? []);
        $video = $streams->first(fn (array $stream) => ($stream['codec_type'] ?? null) === 'video' && empty($stream['disposition']['attached_pic']));

        if (! $video || empty($video['width']) || empty($video['height'])) {
            throw new UnreadableMedia('ffprobe found no video stream: '.trim($result->errorOutput()));
        }

        $duration = (float) ($info['format']['duration'] ?? $video['duration'] ?? 0);

        if ($duration <= 0) {
            throw new UnreadableMedia('ffprobe found no duration.');
        }

        $rotation = $this->rotation($video);
        $turned = abs($rotation) % 180 === 90;

        return [
            'width' => $turned ? $video['height'] : $video['width'],
            'height' => $turned ? $video['width'] : $video['height'],
            'stored_width' => $video['width'],
            'stored_height' => $video['height'],
            'duration_ms' => (int) round($duration * 1000),
            'captured_at' => $this->capturedAt($info['format']['tags'] ?? [], $video['tags'] ?? []),
            'media_info' => array_filter([
                'container' => $info['format']['format_name'] ?? null,
                'codec' => $video['codec_name'] ?? null,
                'profile' => $video['profile'] ?? null,
                'pixel_format' => $video['pix_fmt'] ?? null,
                'frame_rate' => $this->frameRate($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null),
                'bit_rate' => isset($info['format']['bit_rate']) ? (int) $info['format']['bit_rate'] : null,
                'rotation' => $rotation ?: null,
                'color_transfer' => $video['color_transfer'] ?? null,
                'audio' => $streams->contains(fn (array $stream) => ($stream['codec_type'] ?? null) === 'audio'),
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * One upright frame as PNG, taken a little into the clip: the very first frames are often black.
     */
    public function frame(string $path, int $durationMs): string
    {
        $at = min($durationMs * 0.1, 5000) / 1000;
        $output = $this->tempFile('frame');

        $this->ffmpeg([
            '-ss', sprintf('%.3f', $at), '-i', $path,
            '-frames:v', '1', '-f', 'image2', '-c:v', 'png', $output,
        ], $output);

        return $output;
    }

    /**
     * H.264 copy without sound, short side up to video_preview_height, ready to stream (faststart).
     * `$onProgress` gets 0–1 as the encoding goes.
     *
     * @param  callable(float): void|null  $onProgress
     * @return array{path: string, width: int, height: int}
     */
    public function preview(string $path, int $durationMs, ?callable $onProgress = null): array
    {
        $short = config('assets.video_preview_height');
        $output = $this->tempFile('video');

        // Rotation is applied before the filter, so iw and ih are the upright size. -2 keeps the side even.
        $scale = "scale='if(gte(iw,ih),-2,min({$short},iw))':'if(gte(iw,ih),min({$short},ih),-2)'";

        $this->ffmpeg([
            '-i', $path, '-map', '0:v:0', '-an', '-sn', '-dn',
            '-vf', $scale, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart', '-f', 'mp4', '-progress', 'pipe:1', '-nostats', $output,
        ], $output, function (string $type, string $buffer) use ($durationMs, $onProgress) {
            if ($onProgress && $type === 'out' && preg_match_all('/out_time_us=(\d+)/', $buffer, $matches)) {
                $onProgress(min(1, (int) end($matches[1]) / 1000 / max(1, $durationMs)));
            }
        });

        $probe = $this->probe($output);

        return ['path' => $output, 'width' => $probe['width'], 'height' => $probe['height']];
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function ffmpeg(array $arguments, string $output, ?callable $onOutput = null): void
    {
        $result = Process::timeout(config('assets.ffmpeg_timeout'))->run([
            config('assets.ffmpeg'), '-hide_banner', '-v', 'error', '-nostdin', '-y',
            '-threads', (string) max(1, config('assets.ffmpeg_threads')),
            ...$arguments,
        ], $onOutput);

        if ($result->failed() || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);

            throw new UnreadableMedia('ffmpeg failed: '.trim($result->errorOutput()));
        }
    }

    protected function tempFile(string $prefix): string
    {
        return tempnam(sys_get_temp_dir(), $prefix);
    }

    /**
     * Rotation the player applies: the display matrix of newer files, the rotate tag of older ones.
     */
    protected function rotation(array $video): int
    {
        foreach ($video['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                return (int) $side['rotation'];
            }
        }

        return (int) ($video['tags']['rotate'] ?? 0);
    }

    /**
     * The camera's local capture time when the file has it (iPhone), otherwise the container's UTC creation time.
     */
    protected function capturedAt(array $formatTags, array $streamTags): ?Carbon
    {
        $value = Arr::first([
            $formatTags['com.apple.quicktime.creationdate'] ?? null,
            $formatTags['creation_time'] ?? null,
            $streamTags['creation_time'] ?? null,
        ], fn ($value) => filled($value));

        try {
            return $value ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function frameRate(?string $rate): ?float
    {
        if (! $rate || ! str_contains($rate, '/')) {
            return null;
        }

        [$num, $den] = array_map('floatval', explode('/', $rate));

        return $den > 0 && $num > 0 ? round($num / $den, 3) : null;
    }
}
