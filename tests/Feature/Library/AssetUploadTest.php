<?php

namespace Tests\Feature\Library;

use App\Enums\AssetFileRole;
use App\Enums\AssetUploadStatus;
use App\Jobs\ProcessAssetUpload;
use App\Models\Asset;
use App\Models\AssetUpload;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AssetUploadTest extends TestCase
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

    protected function openBatch(int $files = 1): string
    {
        return $this->postJson('/api/assets/uploads', ['files' => $files])->assertCreated()->json('data.id');
    }

    /**
     * Multipart request with a JSON response and its own idempotency key.
     */
    protected function upload(string $batch, array $data)
    {
        return $this->post("/api/assets/uploads/{$batch}/files", $data, [
            'Accept' => 'application/json',
            'Idempotency-Key' => (string) Str::uuid(),
        ]);
    }

    public function test_opening_a_batch_records_the_owner_and_the_file_count(): void
    {
        Bus::fake();

        $this->openBatch(3);

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === 'asset-upload'
            && $batch->options['files'] === 3
            && $batch->options['user_id'] === $this->user->id);
    }

    public function test_upload_is_staged_locally_and_queued_without_creating_a_work(): void
    {
        Bus::fake();
        $batch = $this->openBatch();

        $this->upload($batch, ['file' => UploadedFile::fake()->image('IMG_2041.jpg', 600, 400)])
            ->assertCreated()
            ->assertJsonPath('data.filename', 'IMG_2041.jpg')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.asset', null);

        $upload = AssetUpload::sole();
        Storage::disk(AssetUpload::STAGING_DISK)->assertExists($upload->stagingPath());
        $this->assertSame(0, Asset::count());
        $this->assertSame([], Storage::disk('r2-private')->allFiles());
    }

    public function test_processing_creates_the_work_and_moves_the_file_to_the_private_disk(): void
    {
        $batch = $this->openBatch();

        // The queue is synchronous in tests: the job has run by the time the response comes.
        $response = $this->upload($batch, ['file' => UploadedFile::fake()->image('IMG_2041.jpg', 600, 400)])->assertCreated();

        $upload = AssetUpload::sole();
        $asset = Asset::sole();
        $original = $asset->file(AssetFileRole::Original);

        $this->assertSame(AssetUploadStatus::Done, $upload->status);
        $this->assertSame($upload->asset_ulid, $asset->ulid);
        $this->assertSame($asset->id, $upload->asset_id);
        $this->assertSame([600, 400], [$asset->width, $asset->height]);
        $this->assertSame('IMG_2041.jpg', $asset->original_filename);
        $this->assertSame("assets/{$this->user->ulid}/{$asset->ulid}/original.jpg", $original->path);
        Storage::disk('r2-private')->assertExists($original->path);
        Storage::disk(AssetUpload::STAGING_DISK)->assertMissing($upload->stagingPath());
        $this->assertSame(100, $upload->progress);
        $response->assertJsonPath('data.status', 'done')->assertJsonPath('data.progress', 100)->assertJsonPath('data.asset.id', $asset->ulid);
    }

    public function test_batch_poll_returns_progress_and_only_uploads_changed_since_the_cursor(): void
    {
        $batch = $this->openBatch(3);
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();

        // Further apart than the overlap a poll looks back past its cursor.
        $this->travel(1)->minute();
        $first = $this->getJson("/api/assets/uploads/{$batch}")
            ->assertOk()
            ->assertJsonPath('data.files', 3)
            ->assertJsonPath('data.done', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonCount(1, 'data.uploads')
            ->assertJsonPath('data.uploads.0.asset.original_filename', 'a.jpg');

        $this->travel(1)->minute();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('b.jpg', 20, 20)])->assertCreated();

        $this->getJson("/api/assets/uploads/{$batch}?cursor=".urlencode($first->json('data.cursor')))
            ->assertJsonPath('data.done', 2)
            ->assertJsonCount(1, 'data.uploads')
            ->assertJsonPath('data.uploads.0.filename', 'b.jpg');
    }

    public function test_another_users_batch_is_not_found(): void
    {
        $batch = $this->openBatch();
        Passport::actingAs(User::factory()->create());

        $this->getJson("/api/assets/uploads/{$batch}")->assertNotFound();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();
    }

    public function test_work_goes_into_the_given_folder(): void
    {
        $folder = $this->user->folders()->create(['name' => 'Iceland']);
        $foreign = User::factory()->create()->folders()->create(['name' => 'Theirs']);
        $batch = $this->openBatch(2);

        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg'), 'folder_id' => $folder->ulid])
            ->assertCreated()
            ->assertJsonPath('data.asset.folder_id', $folder->ulid);

        $this->upload($batch, ['file' => UploadedFile::fake()->image('b.jpg', 20, 20), 'folder_id' => $foreign->ulid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folder_id');
    }

    public function test_a_file_already_in_the_library_is_rejected_before_staging(): void
    {
        $file = UploadedFile::fake()->image('IMG_2041.jpg', 600, 400);
        $batch = $this->openBatch(2);

        $this->upload($batch, ['file' => $file])->assertCreated();
        $this->upload($batch, ['file' => $file])
            ->assertConflict()
            ->assertJsonPath('message', __('library.upload.duplicate', ['name' => 'IMG_2041.jpg']));

        $this->assertSame(1, AssetUpload::count());
        $this->assertSame(1, Asset::count());
    }

    public function test_a_parallel_duplicate_fails_in_the_job_and_leaves_no_files(): void
    {
        Bus::fake();
        $file = UploadedFile::fake()->image('IMG_2041.jpg', 600, 400);
        $batch = $this->openBatch(2);
        $this->upload($batch, ['file' => $file])->assertCreated();
        $this->upload($batch, ['file' => $file])->assertCreated();
        [$first, $second] = AssetUpload::orderBy('id')->get()->all();

        (new ProcessAssetUpload($first))->handle();
        (new ProcessAssetUpload($second->fresh()))->handle();

        $second->refresh();
        $this->assertSame(AssetUploadStatus::Failed, $second->status);
        $this->assertSame(__('library.upload.duplicate', ['name' => 'IMG_2041.jpg']), $second->error);
        $this->assertSame(1, Asset::count());
        $this->assertCount(1, Storage::disk('r2-private')->allFiles());
        Storage::disk(AssetUpload::STAGING_DISK)->assertMissing($second->stagingPath());
    }

    public function test_a_failed_commit_removes_the_stored_file_and_lets_the_job_retry(): void
    {
        Bus::fake();
        $batch = $this->openBatch();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $upload = AssetUpload::sole();

        Asset::creating(fn () => throw new \RuntimeException('Database went away'));

        try {
            (new ProcessAssetUpload($upload))->handle();
            $this->fail('The job should have thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database went away', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('r2-private')->allFiles());
        $this->assertSame(AssetUploadStatus::Processing, $upload->fresh()->status);
        // The steps done so far stay visible while the job waits for its next try.
        $this->assertSame(ProcessAssetUpload::PROGRESS_STORED, $upload->fresh()->progress);
        // The staged file stays for the next try.
        Storage::disk(AssetUpload::STAGING_DISK)->assertExists($upload->stagingPath());
    }

    public function test_after_the_last_try_the_upload_fails_and_its_staged_file_is_removed(): void
    {
        Bus::fake();
        $batch = $this->openBatch();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $upload = AssetUpload::sole();

        (new ProcessAssetUpload($upload))->failed(new \RuntimeException('Disk unavailable'));

        $upload->refresh();
        $this->assertSame(AssetUploadStatus::Failed, $upload->status);
        $this->assertSame(__('library.upload.processing_failed'), $upload->error);
        Storage::disk(AssetUpload::STAGING_DISK)->assertMissing($upload->stagingPath());
        $this->assertSame(0, Asset::count());
    }

    public function test_file_over_the_configured_limit_is_rejected(): void
    {
        config(['assets.upload_max_mb' => 1]);

        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->create('big.jpg', 2048, 'image/jpeg')])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', __('library.upload.too_large', ['max' => 1]));
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', __('library.upload.unsupported'));
    }

    public function test_upload_needs_a_confirmed_email(): void
    {
        $batch = $this->openBatch();
        Passport::actingAs(User::factory()->unverified()->create());

        $this->postJson('/api/assets/uploads', ['files' => 1])->assertForbidden();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();
    }

    public function test_old_uploads_are_pruned_with_their_staged_files(): void
    {
        Bus::fake();
        $batch = $this->openBatch();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $upload = AssetUpload::sole();

        $this->travel(AssetUpload::KEEP_DAYS + 1)->days();
        $this->artisan('model:prune', ['--model' => [AssetUpload::class]])->assertSuccessful();

        $this->assertSame(0, AssetUpload::count());
        Storage::disk(AssetUpload::STAGING_DISK)->assertMissing($upload->stagingPath());
    }

    public function test_library_page_passes_the_upload_limits(): void
    {
        config(['assets.upload_max_mb' => 50]);

        $this->actingAs($this->user, 'web')
            ->get('/library')
            ->assertInertia(fn ($page) => $page->where('upload.max_size_mb', 50)->has('upload.accept'));
    }
}
