<?php

namespace Tests\Feature\Library;

use App\Enums\AssetStatus;
use App\Jobs\DeleteAssetStorage;
use App\Models\Asset;
use App\Models\AssetFile;
use App\Models\AssetUpload;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\TestCase;

class AssetDeleteTest extends TestCase
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
     * A real upload, processed right away (the test queue is synchronous): the work, its file rows and its files in R2.
     */
    protected function uploadWork(string $name = 'a.jpg', ?UploadedFile $file = null)
    {
        $batch = $this->postJson('/api/assets/uploads', ['files' => 1])->assertCreated()->json('data.id');

        return $this->post("/api/assets/uploads/{$batch}/files", ['file' => $file ?? UploadedFile::fake()->image($name, 800, 600)], [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    protected function archivedWork(): Asset
    {
        $this->uploadWork()->assertCreated();
        $asset = Asset::sole();
        $asset->update(['status' => AssetStatus::Archived]);

        return $asset;
    }

    protected function prefix(Asset $asset): string
    {
        return "assets/{$this->user->ulid}/{$asset->ulid}";
    }

    public function test_archived_work_is_deleted_with_its_rows_and_its_files(): void
    {
        $asset = $this->archivedWork();
        $files = $asset->files->map(fn (AssetFile $file) => [$file->disk, $file->path])->all();
        $this->assertCount(3, $files);
        // A leftover of an earlier failed attempt under the work's prefix goes too.
        Storage::disk('r2-public')->put($this->prefix($asset).'/preview.0badf00d.jpg', 'x');

        $this->deleteJson("/api/assets/{$asset->ulid}")->assertNoContent();

        $this->assertSame(0, Asset::withTrashed()->count());
        $this->assertSame(0, AssetFile::count());
        $this->assertNull(AssetUpload::sole()->asset_id);

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->assertMissing($path);
        }

        $this->assertSame([], Storage::disk('r2-public')->allFiles());
        $this->assertSame([], Storage::disk('r2-private')->allFiles());
    }

    public function test_the_same_file_can_be_uploaded_again_after_deleting(): void
    {
        $file = UploadedFile::fake()->image('a.jpg', 800, 600);
        $this->uploadWork(file: $file)->assertCreated();
        Asset::sole()->update(['status' => AssetStatus::Archived]);

        $this->deleteJson('/api/assets/'.Asset::sole()->ulid)->assertNoContent();

        $this->uploadWork(file: $file)->assertCreated();
        $this->assertSame(1, Asset::count());
    }

    public function test_works_outside_the_archive_cannot_be_deleted(): void
    {
        $this->uploadWork()->assertCreated();
        $asset = Asset::sole();

        foreach ([AssetStatus::Draft, AssetStatus::Ready] as $status) {
            $asset->update(['status' => $status]);

            $this->deleteJson("/api/assets/{$asset->ulid}")
                ->assertConflict()
                ->assertJsonPath('message', __('library.delete.not_archived'));
        }

        $this->assertModelExists($asset);
        $this->assertNotEmpty(Storage::disk('r2-public')->allFiles());
        $this->assertNotEmpty(Storage::disk('r2-private')->allFiles());
    }

    public function test_another_users_work_is_not_found(): void
    {
        $asset = $this->archivedWork();
        Passport::actingAs(User::factory()->create());

        $this->deleteJson("/api/assets/{$asset->ulid}")->assertNotFound();
        $this->assertModelExists($asset);
    }

    public function test_deleting_needs_a_confirmed_email(): void
    {
        $asset = $this->archivedWork();
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->deleteJson("/api/assets/{$asset->ulid}")->assertForbidden();
        $this->assertModelExists($asset);
    }

    public function test_files_are_removed_only_after_the_rows_are_gone(): void
    {
        // Only the cleanup is held back; the upload itself is processed as usual.
        Queue::fake([DeleteAssetStorage::class]);
        $asset = $this->archivedWork();

        $this->deleteJson("/api/assets/{$asset->ulid}")->assertNoContent();

        Queue::assertPushed(DeleteAssetStorage::class, fn (DeleteAssetStorage $job) => $job->assetUlid === $asset->ulid
            && $job->userUlid === $this->user->ulid);
    }

    public function test_files_left_after_the_last_try_are_logged(): void
    {
        Log::spy();

        (new DeleteAssetStorage($this->user->ulid, 'gone'))->failed(new RuntimeException('R2 is down'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => str_contains($message, "assets/{$this->user->ulid}/gone"));
    }
}
