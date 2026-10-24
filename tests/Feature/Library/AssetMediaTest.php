<?php

namespace Tests\Feature\Library;

use App\Enums\AssetFileRole;
use App\Enums\AssetUploadStatus;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\AssetUpload;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Previews of videos and vectors (KB-19), made by the real ffmpeg, Ghostscript and librsvg.
 * A test is skipped when its tool is not installed.
 */
class AssetMediaTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        Storage::fake(AssetUpload::STAGING_DISK);
        Storage::fake('r2-private');
        Storage::fake('r2-public');
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function requireTool(string $binary): void
    {
        if (! Process::run(['which', $binary])->successful()) {
            $this->markTestSkipped("{$binary} is not installed.");
        }
    }

    /**
     * Opens a batch for one file and sends it; the queue is synchronous, so the job runs right away.
     */
    protected function uploadFile(UploadedFile $file)
    {
        $batch = $this->postJson('/api/assets/uploads', ['files' => 1])->assertCreated()->json('data.id');

        return $this->post("/api/assets/uploads/{$batch}/files", ['file' => $file], [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated();
    }

    /**
     * A test video made by ffmpeg: a test pattern with a tone, optionally with a display rotation.
     * A real file, so its MIME type is detected from the content, as for a real upload
     * (a fake file reports the type of its extension: application/mp4 for .mp4).
     */
    protected function video(string $name, int $width = 1920, int $height = 1080, ?int $rotation = null): UploadedFile
    {
        $this->requireTool('ffmpeg');

        $path = $this->tempPath($name);
        $this->ffmpeg([
            '-f', 'lavfi', '-i', "testsrc=duration=2:size={$width}x{$height}:rate=25",
            '-f', 'lavfi', '-i', 'sine=duration=2', '-shortest',
            '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-c:a', 'aac', $path,
        ]);

        if ($rotation !== null) {
            $rotated = $this->tempPath($name);
            $this->ffmpeg(['-display_rotation:v:0', (string) $rotation, '-i', $path, '-c', 'copy', $rotated]);
            $path = $rotated;
        }

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * A file in the temp directory under the given name, removed after the test.
     */
    protected function tempPath(string $name, ?string $content = null): string
    {
        $directory = sys_get_temp_dir().'/'.Str::random(12);
        mkdir($directory);
        $this->beforeApplicationDestroyed(fn () => $this->app['files']->deleteDirectory($directory));

        $path = $directory.'/'.$name;

        if ($content !== null) {
            file_put_contents($path, $content);
        }

        return $path;
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function ffmpeg(array $arguments): void
    {
        $result = Process::run(['ffmpeg', '-v', 'error', '-y', ...$arguments]);
        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    protected function assertNoStoredFiles(): void
    {
        $this->assertSame([], Storage::disk('r2-private')->allFiles());
        $this->assertSame([], Storage::disk('r2-public')->allFiles());
        $this->assertSame([], Storage::disk(AssetUpload::STAGING_DISK)->allFiles());
    }

    protected function assertFailed(): void
    {
        $upload = AssetUpload::sole();
        $this->assertSame(AssetUploadStatus::Failed, $upload->status);
        $this->assertSame(__('library.upload.processing_failed'), $upload->error);
        $this->assertSame(0, Asset::count());
        $this->assertNoStoredFiles();
    }

    public function test_video_gets_its_details_a_frame_preview_and_a_playback_copy(): void
    {
        $this->uploadFile($this->video('clip.mp4'));

        $asset = Asset::sole();
        $this->assertSame(MediaType::Video, $asset->type);
        $this->assertSame([1920, 1080], [$asset->width, $asset->height]);
        $this->assertEqualsWithDelta(2000, $asset->duration_ms, 100);
        $this->assertSame('h264', $asset->media_info['codec']);
        $this->assertTrue($asset->media_info['audio']);
        $this->assertSame(100, AssetUpload::sole()->progress);

        $thumbnail = $asset->file(AssetFileRole::Thumbnail);
        $this->assertSame([480, 270], [$thumbnail->width, $thumbnail->height]);
        $this->assertSame('image/jpeg', $thumbnail->mime);

        $video = $asset->file(AssetFileRole::VideoPreview);
        $this->assertSame('video/mp4', $video->mime);
        $this->assertSame([1280, 720], [$video->width, $video->height]);
        $this->assertStringEndsWith('.mp4', $video->path);
        Storage::disk('r2-public')->assertExists($video->path);

        $this->getJson('/api/assets/uploads/'.AssetUpload::sole()->batch_id)
            ->assertJsonPath('data.uploads.0.asset.preview_url', $video->url())
            ->assertJsonPath('data.uploads.0.asset.thumbnail_url', $thumbnail->url());
    }

    public function test_rotated_video_is_upright_while_the_original_keeps_its_stored_size(): void
    {
        $this->uploadFile($this->video('portrait.mov', rotation: 90));

        $asset = Asset::sole();
        $this->assertSame([1080, 1920], [$asset->width, $asset->height]);
        $this->assertSame(90, abs($asset->media_info['rotation']));
        $this->assertSame([1920, 1080], [$asset->file(AssetFileRole::Original)->width, $asset->file(AssetFileRole::Original)->height]);
        $this->assertSame([720, 1280], [$asset->file(AssetFileRole::VideoPreview)->width, $asset->file(AssetFileRole::VideoPreview)->height]);
        $this->assertSame([270, 480], [$asset->file(AssetFileRole::Thumbnail)->width, $asset->file(AssetFileRole::Thumbnail)->height]);
    }

    public function test_small_video_is_not_enlarged(): void
    {
        $this->uploadFile($this->video('small.mp4', 640, 360));

        $video = Asset::sole()->file(AssetFileRole::VideoPreview);
        $this->assertSame([640, 360], [$video->width, $video->height]);
    }

    public function test_broken_video_fails_at_once_without_files(): void
    {
        // The start of a real MP4: detected as video/mp4, but without the data ffprobe needs.
        $content = substr($this->video('clip.mp4')->getContent(), 0, 2048);
        $broken = $this->tempPath('broken.mp4', $content);

        $this->uploadFile(new UploadedFile($broken, 'broken.mp4', null, null, true));

        $this->assertFailed();
    }

    public function test_svg_gets_a_raster_companion_and_previews(): void
    {
        $this->requireTool('rsvg-convert');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="200" viewBox="0 0 400 200">'
            .'<defs><linearGradient id="g"><stop offset="0" stop-color="#f53003"/><stop offset="1" stop-color="#1b1b18"/></linearGradient></defs>'
            .'<rect width="400" height="200" fill="url(#g)"/></svg>';

        $this->uploadFile(UploadedFile::fake()->createWithContent('logo.svg', $svg));

        $asset = Asset::sole();
        $this->assertSame(MediaType::Vector, $asset->type);
        $this->assertSame([400, 200], [$asset->width, $asset->height]);

        $companion = $asset->file(AssetFileRole::RasterCompanion);
        $this->assertSame('image/jpeg', $companion->mime);
        $this->assertSame('r2-private', $companion->disk);
        $this->assertEqualsWithDelta(16, $companion->width * $companion->height / 1_000_000, 0.1);
        $this->assertSame(2, intdiv($companion->width, $companion->height));
        Storage::disk('r2-private')->assertExists($companion->path);

        $this->assertSame([480, 240], [$asset->file(AssetFileRole::Thumbnail)->width, $asset->file(AssetFileRole::Thumbnail)->height]);
        $this->assertSame([1600, 800], [$asset->file(AssetFileRole::Preview)->width, $asset->file(AssetFileRole::Preview)->height]);
    }

    public function test_svg_linking_outside_itself_is_refused_without_rendering(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">'
            .'<image xlink:href="file:///etc/passwd" width="10" height="10"/></svg>';

        $this->uploadFile(UploadedFile::fake()->createWithContent('leak.svg', $svg));

        $this->assertFailed();
    }

    public function test_svg_with_entities_is_refused(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text>&x;</text></svg>';

        $this->uploadFile(UploadedFile::fake()->createWithContent('entity.svg', $svg));

        $this->assertFailed();
    }

    public function test_eps_is_rasterized_at_its_bounding_box(): void
    {
        $this->requireTool('gs');

        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 400 200\n"
            ."0 0.8 1 0 setcmykcolor 0 0 400 200 rectfill\nshowpage\n";

        $this->uploadFile(UploadedFile::fake()->createWithContent('logo.eps', $eps));

        $asset = Asset::sole();
        $this->assertSame([400, 200], [$asset->width, $asset->height]);
        $this->assertStringEndsWith('/original.eps', $asset->file(AssetFileRole::Original)->path);

        $companion = $asset->file(AssetFileRole::RasterCompanion);
        $this->assertEqualsWithDelta(16, $companion->width * $companion->height / 1_000_000, 0.1);
        $this->assertSame([480, 240], [$asset->file(AssetFileRole::Thumbnail)->width, $asset->file(AssetFileRole::Thumbnail)->height]);
    }

    public function test_broken_eps_fails_at_once_without_files(): void
    {
        $this->requireTool('gs');

        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\n{{{ this is not postscript\n";

        $this->uploadFile(UploadedFile::fake()->createWithContent('broken.eps', $eps));

        $this->assertFailed();
    }
}
