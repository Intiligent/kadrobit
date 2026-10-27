<?php

namespace Tests\Feature\Library;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Enums\MediaType;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\AssetListing;
use App\Models\AssetUpload;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\Assets\AssetRotator;
use App\Services\Assets\PreviewGenerator;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Laravel\Passport\Passport;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AssetRotateTest extends TestCase
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

    /**
     * A real upload, processed right away (the test queue is synchronous).
     */
    protected function uploadWork(UploadedFile $file): Asset
    {
        $batch = $this->postJson('/api/assets/uploads', ['files' => 1])->assertCreated()->json('data.id');

        $this->post("/api/assets/uploads/{$batch}/files", ['file' => $file], [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated();

        return Asset::sole();
    }

    /**
     * 60×40 JPEG, red on the left half, black on the right; with an EXIF orientation when given.
     */
    protected function jpeg(?int $orientation = null): UploadedFile
    {
        $image = imagecreatetruecolor(60, 40);
        imagefilledrectangle($image, 0, 0, 29, 39, 0xFF0000);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();

        if ($orientation) {
            $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00\x00\x00\x00\x00";
            $app1 = "Exif\x00\x00".$tiff;
            $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
        }

        return UploadedFile::fake()->createWithContent('IMG_1.jpg', $jpeg);
    }

    protected function original(Asset $asset): AssetFile
    {
        return $asset->files()->where('role', AssetFileRole::Original)->sole();
    }

    /**
     * Whether the pixel at the given point of the stored original is red.
     */
    protected function isRed(AssetFile $file, int $x, int $y): bool
    {
        $image = new Imagick;
        $image->readImageBlob($file->storage()->get($file->path));
        $pixel = $image->getImagePixelColor($x, $y)->getColor();

        return $pixel['r'] > 200 && $pixel['g'] < 60 && $pixel['b'] < 60;
    }

    public function test_rotating_right_replaces_the_original_and_its_previews(): void
    {
        $asset = $this->uploadWork($this->jpeg());
        $before = $asset->files->mapWithKeys(fn (AssetFile $file) => [$file->role->value => $file->path])->all();
        $checksum = $asset->checksum;

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90])
            ->assertOk()
            ->assertJsonPath('data.width', 40)
            ->assertJsonPath('data.height', 60);

        $asset->refresh();
        $original = $this->original($asset);
        $this->assertSame([40, 60], [$original->width, $original->height]);
        // The left half went up: red is now at the top.
        $this->assertTrue($this->isRed($original, 20, 5));
        $this->assertFalse($this->isRed($original, 20, 55));
        $this->assertSame(hash('sha256', $original->storage()->get($original->path)), $original->checksum);
        $this->assertSame([40, 60], [$asset->file(AssetFileRole::Preview)->width, $asset->file(AssetFileRole::Preview)->height]);
        // Uploading the same file again is still a duplicate.
        $this->assertSame($checksum, $asset->checksum);

        // New files at new paths, the old ones gone.
        foreach ($asset->files as $file) {
            $this->assertNotSame($before[$file->role->value], $file->path);
            $this->assertTrue($file->fileExists());
            Storage::disk($file->disk)->assertMissing($before[$file->role->value]);
        }

        $this->assertCount(1, Storage::disk('r2-private')->allFiles());
        $this->assertCount(2, Storage::disk('r2-public')->allFiles());
    }

    public function test_rotating_left_turns_the_other_way(): void
    {
        $asset = $this->uploadWork($this->jpeg());

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 270])->assertOk();

        // The left half went down.
        $this->assertTrue($this->isRed($this->original($asset), 20, 55));
    }

    public function test_the_exif_orientation_is_applied_before_the_turn_and_reset(): void
    {
        // Stored 60×40, seen upright as 40×60 with the red half at the top.
        $asset = $this->uploadWork($this->jpeg(6));
        $this->assertSame([40, 60], [$asset->width, $asset->height]);

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90])->assertOk();

        $asset->refresh();
        $original = $this->original($asset);
        $this->assertSame([60, 40], [$asset->width, $asset->height]);
        $this->assertSame([60, 40], [$original->width, $original->height]);
        $this->assertTrue($this->isRed($original, 55, 20));

        $local = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($local, $original->storage()->get($original->path));
        $this->assertSame(1, (int) ((@exif_read_data($local) ?: [])['Orientation'] ?? 1));
        @unlink($local);
    }

    public function test_a_half_turn_keeps_the_size_and_marks_the_work_newer(): void
    {
        $asset = $this->uploadWork($this->jpeg());
        $asset->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 180])->assertOk();

        $asset->refresh();
        $this->assertSame([60, 40], [$asset->width, $asset->height]);
        $this->assertTrue($this->isRed($this->original($asset), 55, 20));
        $this->assertTrue($asset->updated_at->isAfter(now()->subMinute()));
    }

    public function test_only_quarter_turns_are_taken(): void
    {
        $asset = $this->uploadWork($this->jpeg());

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 45])->assertUnprocessable()->assertJsonValidationErrors('degrees');
        $this->postJson("/api/assets/{$asset->ulid}/rotate")->assertUnprocessable()->assertJsonValidationErrors('degrees');
    }

    public function test_works_of_other_users_are_not_found(): void
    {
        $asset = $this->uploadWork($this->jpeg());
        Passport::actingAs(User::factory()->create());

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90])->assertNotFound();
    }

    public function test_videos_vectors_submitted_and_archived_works_are_not_rotated(): void
    {
        $asset = $this->uploadWork($this->jpeg());
        $path = $this->original($asset)->path;

        $cases = [
            [['type' => MediaType::Video], 'library.tools.rotate_unsupported'],
            [['type' => MediaType::Vector], 'library.tools.rotate_unsupported'],
            [['status' => AssetStatus::Submitted], 'library.tools.rotate_not_editable'],
            [['status' => AssetStatus::Archived], 'library.tools.rotate_not_editable'],
        ];

        foreach ($cases as [$attributes, $message]) {
            $asset->update(['type' => MediaType::Photo, 'status' => AssetStatus::Draft, ...$attributes]);

            $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90])
                ->assertConflict()
                ->assertJsonPath('message', __($message));
        }

        $this->assertSame($path, $this->original($asset)->path);
    }

    public function test_a_work_queued_for_a_marketplace_is_not_rotated(): void
    {
        $this->seed(MarketplaceSeeder::class);
        $asset = $this->uploadWork($this->jpeg());
        $asset->update(['status' => AssetStatus::Ready]);
        $marketplace = Marketplace::where('code', 'shutterstock')->sole();
        $account = $this->user->marketplaceAccounts()->create([
            'marketplace_id' => $marketplace->id,
            'label' => $marketplace->name,
            'host' => 'ftp.example.com',
            'login' => 'akadrov',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
        ]);
        $listing = AssetListing::create(['asset_id' => $asset->id, 'marketplace_account_id' => $account->id, 'status' => ListingStatus::Sending]);

        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90])
            ->assertConflict()
            ->assertJsonPath('message', __('library.tools.rotate_publishing'));

        // Once the delivery is over, the work can be turned again.
        $listing->update(['status' => ListingStatus::Failed]);
        $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90])->assertOk();
    }

    public function test_a_failure_after_storing_leaves_the_work_and_its_files_as_they_were(): void
    {
        $asset = $this->uploadWork($this->jpeg());
        $before = $asset->files->map(fn (AssetFile $file) => [$file->role->value, $file->path, $file->size_bytes])->all();

        $rotator = Mockery::mock(AssetRotator::class, [app(PreviewGenerator::class)])->makePartial()->shouldAllowMockingProtectedMethods();
        $rotator->shouldReceive('switchFiles')->andThrow(new RuntimeException('Database is gone'));
        $this->app->instance(AssetRotator::class, $rotator);

        $this->withoutExceptionHandling();

        try {
            $this->postJson("/api/assets/{$asset->ulid}/rotate", ['degrees' => 90]);
            $this->fail('The rotation did not fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Database is gone', $e->getMessage());
        }

        $asset->refresh();
        $this->assertSame($before, $asset->files->map(fn (AssetFile $file) => [$file->role->value, $file->path, $file->size_bytes])->all());
        $this->assertSame([60, 40], [$asset->width, $asset->height]);
        $this->assertCount(1, Storage::disk('r2-private')->allFiles());
        $this->assertCount(2, Storage::disk('r2-public')->allFiles());
    }
}
