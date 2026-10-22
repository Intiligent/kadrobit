<?php

namespace Tests\Feature\Http;

use App\Enums\ErrorCode;
use App\Http\Middleware\EnsureIdempotency;
use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/test')->group(function () {
            Route::post('orders', function () {
                $this->calls++;

                return response()->json(['order' => $this->calls], 201);
            });
            Route::post('failing', function () {
                $this->calls++;

                abort(422, 'Invalid order.');
            });
            Route::post('upload', function () {
                $this->calls++;

                return response()->json(['ok' => true]);
            });
            Route::post('secure', function () {
                $this->calls++;

                return response()->json(['user' => request()->user()->id]);
            })->middleware('auth:api');
        });
    }

    public function test_request_without_key_runs_every_time(): void
    {
        $this->postJson('/api/test/orders')->assertCreated();
        $this->postJson('/api/test/orders')->assertCreated();

        $this->assertSame(2, $this->calls);
    }

    public function test_repeated_key_replays_stored_response(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/test/orders', ['qty' => 1], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJson(['order' => 1])
            ->assertHeaderMissing(EnsureIdempotency::REPLAYED_HEADER);

        $this->postJson('/api/test/orders', ['qty' => 1], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJson(['order' => 1])
            ->assertHeader(EnsureIdempotency::REPLAYED_HEADER, 'true');

        $this->assertSame(1, $this->calls);
    }

    public function test_different_keys_run_separately(): void
    {
        $this->postJson('/api/test/orders', [], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->postJson('/api/test/orders', [], ['Idempotency-Key' => (string) Str::uuid()]);

        $this->assertSame(2, $this->calls);
    }

    public function test_reusing_key_with_different_payload_is_rejected(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/test/orders', ['qty' => 1], ['Idempotency-Key' => $key])->assertCreated();

        $this->postJson('/api/test/orders', ['qty' => 2], ['Idempotency-Key' => $key])
            ->assertUnprocessable()
            ->assertJsonPath('code', ErrorCode::Validation->value)
            ->assertJsonPath('message', __('errors.idempotency.key_reused'));

        $this->assertSame(1, $this->calls);
    }

    public function test_failed_response_is_not_stored_so_retry_runs_again(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/test/failing', [], ['Idempotency-Key' => $key])->assertUnprocessable();
        $this->postJson('/api/test/failing', [], ['Idempotency-Key' => $key])->assertUnprocessable();

        $this->assertSame(2, $this->calls);
    }

    public function test_concurrent_duplicate_is_rejected_with_conflict(): void
    {
        $key = (string) Str::uuid();
        $this->markInProgress($key, ['qty' => 1]);

        $this->postJson('/api/test/orders', ['qty' => 1], ['Idempotency-Key' => $key])
            ->assertConflict()
            ->assertJsonPath('code', ErrorCode::Conflict->value);

        $this->assertSame(0, $this->calls);
    }

    public function test_concurrent_request_with_different_payload_is_rejected_as_key_reuse(): void
    {
        $key = (string) Str::uuid();
        $this->markInProgress($key, ['qty' => 1]);

        $this->postJson('/api/test/orders', ['qty' => 2], ['Idempotency-Key' => $key])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('errors.idempotency.key_reused'));
    }

    public function test_key_is_released_after_a_failed_request(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/test/failing', [], ['Idempotency-Key' => $key])->assertUnprocessable();

        $this->assertNull(Cache::get(EnsureIdempotency::cacheKey('ip:127.0.0.1', 'POST', 'api/test/failing', $key)));
    }

    public function test_large_response_is_not_stored(): void
    {
        config(['idempotency.max_body_size' => 10]);
        $key = (string) Str::uuid();

        $this->postJson('/api/test/orders', [], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/test/orders', [], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeaderMissing(EnsureIdempotency::REPLAYED_HEADER);

        $this->assertSame(2, $this->calls);
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->postJson('/api/test/orders', [], ['Idempotency-Key' => 'not-a-uuid'])
            ->assertBadRequest()
            ->assertJsonPath('message', __('errors.idempotency.invalid_key'));

        $this->assertSame(0, $this->calls);
    }

    public function test_get_requests_ignore_the_key(): void
    {
        Route::middleware('api')->get('api/test/counter', fn () => ++$this->calls);
        $key = (string) Str::uuid();

        $this->getJson('/api/test/counter', ['Idempotency-Key' => $key])->assertOk();
        $this->getJson('/api/test/counter', ['Idempotency-Key' => $key])->assertOk();

        $this->assertSame(2, $this->calls);
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $this->seed(OauthSeeder::class);
        $key = (string) Str::uuid();
        [$alice, $bob] = User::factory()->count(2)->create();

        Passport::actingAs($alice);
        $this->postJson('/api/test/secure', [], ['Idempotency-Key' => $key])->assertJson(['user' => $alice->id]);

        Passport::actingAs($bob);
        $this->postJson('/api/test/secure', [], ['Idempotency-Key' => $key])
            ->assertJson(['user' => $bob->id])
            ->assertHeaderMissing(EnsureIdempotency::REPLAYED_HEADER);

        $this->assertSame(2, $this->calls);
    }

    public function test_failed_upload_does_not_break_fingerprinting(): void
    {
        $file = new UploadedFile(__FILE__, 'huge.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->post('/api/test/upload', ['photo' => $file], ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(1, $this->calls);
    }

    public function test_retried_upload_of_the_same_file_is_replayed(): void
    {
        $key = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('photo.jpg', 'same bytes');

        $this->post('/api/test/upload', ['photo' => $file], ['Idempotency-Key' => $key, 'Accept' => 'application/json'])->assertOk();
        $this->post('/api/test/upload', ['photo' => $file], ['Idempotency-Key' => $key, 'Accept' => 'application/json'])
            ->assertOk()
            ->assertHeader(EnsureIdempotency::REPLAYED_HEADER, 'true');

        $this->assertSame(1, $this->calls);
    }

    /**
     * Simulates a first request with the same key that is still running.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function markInProgress(string $key, array $payload): void
    {
        $fingerprint = hash('sha256', json_encode([[], $payload, []]));

        Cache::put(
            EnsureIdempotency::cacheKey('ip:127.0.0.1', 'POST', 'api/test/orders', $key),
            ['state' => 'processing', 'fingerprint' => $fingerprint],
            60,
        );
    }
}
