<?php

namespace Tests\Feature\Library;

use App\Enums\AssetFileRole;
use App\Enums\AssetUploadStatus;
use App\Enums\UserSettingKey;
use App\Http\Requests\Assets\StoreAssetUploadRequest;
use App\Jobs\GenerateAssetMetadata;
use App\Jobs\ProcessAssetUpload;
use App\Models\Asset;
use App\Models\AssetUpload;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
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

    protected function process(AssetUpload $upload): void
    {
        app()->call([new ProcessAssetUpload($upload), 'handle']);
    }

    public function test_opening_a_batch_records_the_owner_and_the_file_count(): void
    {
        Bus::fake();

        $this->openBatch(3);

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === 'asset-upload'
            && $batch->options['files'] === 3
            && $batch->options['user_id'] === $this->user->id
            // Processing has its own queue, away from emails on "default".
            && $batch->options['queue'] === 'uploads');
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

    public function test_photo_gets_a_thumbnail_and_a_preview_on_the_public_disk(): void
    {
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->image('IMG_1.jpg', 3000, 2000)])->assertCreated();

        $asset = Asset::sole();
        $thumbnail = $asset->file(AssetFileRole::Thumbnail);
        $preview = $asset->file(AssetFileRole::Preview);

        $this->assertSame([480, 320], [$thumbnail->width, $thumbnail->height]);
        $this->assertSame([1600, 1067], [$preview->width, $preview->height]);

        foreach ([$thumbnail, $preview] as $file) {
            $this->assertSame('r2-public', $file->disk);
            $this->assertSame('image/jpeg', $file->mime);
            $this->assertMatchesRegularExpression(
                "~^assets/{$this->user->ulid}/{$asset->ulid}/{$file->role->value}\\.[0-9a-f]{8}\\.jpg$~",
                $file->path,
            );
            $this->assertSame(substr($file->checksum, 0, 8), explode('.', basename($file->path))[1]);
            Storage::disk('r2-public')->assertExists($file->path);
        }
    }

    public function test_small_photo_previews_are_not_enlarged_and_transparency_turns_white(): void
    {
        $image = imagecreatetruecolor(200, 100);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = UploadedFile::fake()->createWithContent('logo.png', ob_get_clean());

        $this->upload($this->openBatch(), ['file' => $png])->assertCreated();

        $thumbnail = Asset::sole()->file(AssetFileRole::Thumbnail);
        $this->assertSame([200, 100], [$thumbnail->width, $thumbnail->height]);

        $copy = imagecreatefromstring(Storage::disk('r2-public')->get($thumbnail->path));
        $this->assertSame([255, 255, 255], array_values(array_slice(imagecolorsforindex($copy, imagecolorat($copy, 10, 10)), 0, 3)));
    }

    public function test_batch_poll_returns_only_uploads_changed_since_the_cursor(): void
    {
        $batch = $this->openBatch(3);
        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();

        // Further apart than the overlap a poll looks back past its cursor.
        $this->travel(1)->minute();
        $first = $this->getJson("/api/assets/uploads/{$batch}")
            ->assertOk()
            ->assertJsonMissingPath('data.done')
            ->assertJsonCount(1, 'data.uploads')
            ->assertJsonPath('data.uploads.0.asset.original_filename', 'a.jpg');

        $this->travel(1)->minute();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('b.jpg', 20, 20)])->assertCreated();

        $this->getJson("/api/assets/uploads/{$batch}?cursor=".urlencode($first->json('data.cursor')))
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

        $this->process($first);
        $this->process($second->fresh());

        $second->refresh();
        $this->assertSame(AssetUploadStatus::Failed, $second->status);
        $this->assertSame(__('library.upload.duplicate', ['name' => 'IMG_2041.jpg']), $second->error);
        $this->assertSame(1, Asset::count());
        $this->assertCount(1, Storage::disk('r2-private')->allFiles());
        $this->assertCount(2, Storage::disk('r2-public')->allFiles());
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
            $this->process($upload);
            $this->fail('The job should have thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database went away', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('r2-private')->allFiles());
        $this->assertSame([], Storage::disk('r2-public')->allFiles());
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
        config(['assets.upload_max_mb' => 1, 'assets.upload_batch_max_files' => 40]);

        $this->actingAs($this->user, 'web')
            ->get('/library')
            ->assertInertia(fn ($page) => $page
                ->where('upload.max_size_mb', 1)
                ->where('upload.max_batch_files', 40)
                ->has('upload.accept'));
    }

    public function test_library_page_hands_back_batches_still_at_work_after_a_reload(): void
    {
        Bus::fake();
        $active = $this->openBatch(3);
        $this->upload($active, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $this->upload($active, ['file' => UploadedFile::fake()->image('b.jpg', 20, 20)])->assertCreated();
        AssetUpload::where('filename', 'b.jpg')->update(['status' => AssetUploadStatus::Done, 'progress' => 100]);

        // A batch whose files are all processed is not brought back.
        $finished = $this->openBatch(1);
        $this->upload($finished, ['file' => UploadedFile::fake()->image('c.jpg', 30, 30)])->assertCreated();
        AssetUpload::where('filename', 'c.jpg')->update(['status' => AssetUploadStatus::Done]);

        $this->actingAs($this->user, 'web')
            ->get('/library')
            ->assertInertia(fn ($page) => $page
                ->has('upload.batches', 1)
                ->where('upload.batches.0.id', $active)
                // Three announced, two received: the page was left before the third was sent.
                ->where('upload.batches.0.missing', 1)
                ->where('upload.batches.0.uploads.0.filename', 'a.jpg')
                ->where('upload.batches.0.uploads.0.status', 'processing')
                ->where('upload.batches.0.uploads.0.mime', 'image/jpeg')
                ->where('upload.batches.0.uploads.1.status', 'done'));
    }

    public function test_size_limit_never_exceeds_what_php_accepts(): void
    {
        config(['assets.upload_max_mb' => 100000]);

        $this->assertSame(intdiv(SymfonyUploadedFile::getMaxFilesize(), 1024 * 1024), StoreAssetUploadRequest::maxSizeMb());
    }

    public function test_batch_takes_only_the_announced_number_of_files(): void
    {
        $batch = $this->openBatch(1);

        $this->upload($batch, ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $this->upload($batch, ['file' => UploadedFile::fake()->image('b.jpg', 20, 20)])
            ->assertConflict()
            ->assertJsonPath('message', __('library.upload.batch_full'));
    }

    public function test_eps_keeps_its_extension(): void
    {
        $eps = UploadedFile::fake()->createWithContent('logo.eps', "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n");

        $this->upload($this->openBatch(), ['file' => $eps])->assertCreated();

        $asset = Asset::sole();
        $this->assertSame('vector', $asset->type->value);
        $this->assertStringEndsWith('/original.eps', $asset->file(AssetFileRole::Original)->path);
    }

    public function test_work_size_is_upright_while_the_original_keeps_its_stored_size(): void
    {
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->createWithContent('portrait.jpg', $this->jpegWithOrientation(60, 40, 6))])
            ->assertCreated();

        $asset = Asset::sole();
        $this->assertSame([40, 60], [$asset->width, $asset->height]);
        $this->assertSame([60, 40], [$asset->file(AssetFileRole::Original)->width, $asset->file(AssetFileRole::Original)->height]);
        $this->assertSame([40, 60], [$asset->file(AssetFileRole::Preview)->width, $asset->file(AssetFileRole::Preview)->height]);
    }

    public function test_a_second_run_of_a_finished_job_leaves_the_work_and_its_files_alone(): void
    {
        Bus::fake();
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $stale = AssetUpload::sole();
        $this->process(AssetUpload::sole());

        // A copy of the job that still holds the upload as "processing".
        $this->process($stale);

        $this->assertSame(AssetUploadStatus::Done, $stale->fresh()->status);
        $this->assertSame(1, Asset::count());
        $this->assertCount(1, Storage::disk('r2-private')->allFiles());
        $this->assertCount(2, Storage::disk('r2-public')->allFiles());
    }

    public function test_the_job_never_runs_twice_at_once_for_the_same_upload(): void
    {
        Bus::fake();
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $job = new ProcessAssetUpload(AssetUpload::sole());

        $middleware = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertSame(AssetUpload::sole()->ulid, $middleware->key);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
    }

    /**
     * A JPEG whose EXIF says how to turn it, the way phone cameras store portraits.
     */
    public function test_metadata_embedded_in_the_file_is_kept_and_marked_as_the_files(): void
    {
        $data = '';

        foreach (['2#005' => 'Sunset over the sea', '2#025' => 'Sunset'] as $tag => $value) {
            $data .= "\x1C\x02".chr((int) substr($tag, 2)).pack('n', strlen($value)).$value;
        }

        $jpeg = tempnam(sys_get_temp_dir(), 'iptc');
        file_put_contents($jpeg, UploadedFile::fake()->image('x.jpg', 60, 40)->getContent());
        file_put_contents($jpeg, iptcembed($data, $jpeg));

        $this->upload($this->openBatch(), ['file' => new UploadedFile($jpeg, 'IMG_1.jpg', 'image/jpeg', test: true)])->assertCreated();

        $asset = Asset::sole();
        $this->assertSame('Sunset over the sea', $asset->title);
        $this->assertSame(['sunset'], $asset->keywords);
        $this->assertEquals(['title' => 'file', 'keywords' => 'file'], $asset->metadata_sources);
        @unlink($jpeg);
    }

    public function test_generation_is_queued_after_processing_when_the_setting_is_on(): void
    {
        Queue::fake([GenerateAssetMetadata::class]);
        config(['metadata.drivers.openai.key' => 'sk-test']);

        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->image('IMG_1.jpg', 60, 40)])->assertCreated();

        Queue::assertPushed(GenerateAssetMetadata::class, fn ($job) => $job->asset->is(Asset::sole()));
        // Marked as generating, so the library waits for the notice.
        $this->assertNotNull(Asset::sole()->metadata_generating_at);
    }

    public function test_generation_is_not_queued_with_the_setting_off_or_without_a_key(): void
    {
        Queue::fake([GenerateAssetMetadata::class]);

        // No key: the provider is not set up.
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->image('IMG_1.jpg', 60, 40)])->assertCreated();

        config(['metadata.drivers.openai.key' => 'sk-test']);
        $this->user->setSetting(UserSettingKey::MetadataAutofillOnUpload, false);
        $this->upload($this->openBatch(), ['file' => UploadedFile::fake()->image('IMG_2.jpg', 70, 40)])->assertCreated();

        $this->assertSame(2, Asset::count());
        Queue::assertNotPushed(GenerateAssetMetadata::class);
    }

    protected function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));
        $jpeg = ob_get_clean();

        $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }
}
