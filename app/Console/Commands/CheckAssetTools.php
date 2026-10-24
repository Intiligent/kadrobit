<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Checks the programs upload processing runs (config/assets.php, ASSETS_* in .env): run it after
 * setting the paths on a server, as the user the queue workers run as.
 */
class CheckAssetTools extends Command
{
    protected $signature = 'assets:tools';

    protected $description = 'Check the ffmpeg, Ghostscript and librsvg programs used for previews';

    public function handle(): int
    {
        $tools = [
            ['ffmpeg', config('assets.ffmpeg'), ['-hide_banner', '-version']],
            ['ffprobe', config('assets.ffprobe'), ['-hide_banner', '-version']],
            ['Ghostscript', config('assets.ghostscript'), ['--version']],
            ['rsvg-convert', config('assets.rsvg_convert'), ['--version']],
        ];

        $rows = [];
        $ok = extension_loaded('imagick');
        $rows[] = ['imagick', 'PHP extension', $ok ? 'loaded' : 'MISSING'];

        foreach ($tools as [$name, $binary, $arguments]) {
            try {
                $result = Process::timeout(10)->run([$binary, ...$arguments]);
                $version = $result->successful() ? strtok(trim($result->output()), "\n") : 'FAILED: '.trim($result->errorOutput());
            } catch (Throwable $e) {
                $result = null;
                $version = 'FAILED: '.$e->getMessage();
            }

            $ok = $ok && $result?->successful();
            $rows[] = [$name, $binary, $version];
        }

        // The playback copy is H.264 from libx264.
        $encoders = rescue(fn () => Process::timeout(10)->run([config('assets.ffmpeg'), '-hide_banner', '-encoders'])->output(), '', false);
        $x264 = str_contains($encoders, 'libx264');
        $ok = $ok && $x264;
        $rows[] = ['libx264', 'ffmpeg encoder', $x264 ? 'available' : 'MISSING'];

        $this->table(['Tool', 'Path', 'Version'], $rows);

        if (! $ok) {
            $this->error('Some tools are missing: set their paths in .env (ASSETS_*), see docs/library.md.');

            return self::FAILURE;
        }

        $this->info('All tools are in place.');

        return self::SUCCESS;
    }
}
