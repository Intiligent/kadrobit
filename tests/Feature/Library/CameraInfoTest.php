<?php

namespace Tests\Feature\Library;

use App\Enums\AssetFileRole;
use App\Models\Asset;
use App\Models\AssetUpload;
use App\Models\User;
use App\Services\Assets\CameraInfoReader;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Mockery\MockInterface;
use Tests\TestCase;

class CameraInfoTest extends TestCase
{
    use RefreshDatabase;

    protected const CAMERA = ['make' => 'Apple', 'model' => 'iPhone 13 Pro', 'iso' => 50];

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        Storage::fake(AssetUpload::STAGING_DISK);
        Storage::fake(AssetFileRole::Original->disk());
        Storage::fake(AssetFileRole::Preview->disk());
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    public function test_the_camera_summary_is_read_on_upload_and_returned_with_the_work(): void
    {
        $this->mock(CameraInfoReader::class, fn (MockInterface $mock) => $mock->shouldReceive('read')->andReturn(self::CAMERA));
        $batch = $this->postJson('/api/assets/uploads', ['files' => 1])->json('data.id');

        $this->post("/api/assets/uploads/{$batch}/files", ['file' => UploadedFile::fake()->image('IMG_1.jpg', 60, 40)], [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.asset.camera.model', 'iPhone 13 Pro');

        $this->assertEquals(self::CAMERA, Asset::sole()->media_info['camera']); // jsonb keeps its own key order
    }

    public function test_a_photo_without_exif_has_no_camera(): void
    {
        $batch = $this->postJson('/api/assets/uploads', ['files' => 1])->json('data.id');

        $this->post("/api/assets/uploads/{$batch}/files", ['file' => UploadedFile::fake()->image('IMG_1.jpg', 60, 40)], [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.asset.camera', null);
    }

    public function test_the_command_reads_photos_uploaded_before_and_keeps_other_media_info(): void
    {
        $this->mock(CameraInfoReader::class, fn (MockInterface $mock) => $mock->shouldReceive('read')->once()->andReturn(self::CAMERA));
        $asset = $this->photo(['media_info' => ['profile' => 'Display P3']]);
        $read = $this->photo(['media_info' => ['camera' => ['make' => 'Canon']]]);

        $this->artisan('assets:read-camera-info')->expectsOutputToContain('Read 1 photos.')->assertSuccessful();

        $this->assertEquals(['profile' => 'Display P3', 'camera' => self::CAMERA], $asset->refresh()->media_info);
        // Already read: skipped without --force.
        $this->assertSame(['make' => 'Canon'], $read->refresh()->media_info['camera']);
    }

    public function test_a_missing_original_is_reported_and_the_rest_go_on(): void
    {
        $this->mock(CameraInfoReader::class, fn (MockInterface $mock) => $mock->shouldReceive('read')->andReturn([]));
        $missing = $this->photo(store: false);
        $plain = $this->photo();

        $this->artisan('assets:read-camera-info')->expectsOutputToContain($missing->ulid)->assertFailed();

        // No EXIF: an empty summary, so the next run skips it.
        $this->assertSame([], $plain->refresh()->media_info['camera']);
    }

    protected function photo(array $attributes = [], bool $store = true): Asset
    {
        $asset = $this->user->assets()->create([
            'type' => 'photo', 'original_filename' => 'IMG_1.jpg', 'checksum' => hash('sha256', uniqid('', true)), ...$attributes,
        ]);
        $path = "assets/{$asset->ulid}/original.jpg";

        if ($store) {
            Storage::disk(AssetFileRole::Original->disk())->put($path, 'jpeg');
        }

        $asset->files()->create(['role' => AssetFileRole::Original, 'disk' => AssetFileRole::Original->disk(), 'path' => $path, 'mime' => 'image/jpeg', 'size_bytes' => 4]);

        return $asset;
    }
}
