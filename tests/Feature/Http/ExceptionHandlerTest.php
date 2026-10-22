<?php

namespace Tests\Feature\Http;

use App\Enums\ErrorCode;
use App\Exceptions\ExceptionWithMessage;
use Database\Seeders\OauthSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ExceptionHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Route::middleware('api')->prefix('api/test')->group(function () {
            Route::get('server', fn () => throw new RuntimeException('SQLSTATE secret details'));
            Route::get('forbidden', fn () => throw new AuthorizationException('Not your asset.'));
            Route::get('missing-model', fn () => throw new ModelNotFoundException('No query results for model [App\Models\Asset] 5'));
            Route::get('validation', fn () => throw ValidationException::withMessages(['title' => 'The title is required.']));
            Route::get('with-message', fn () => throw new ExceptionWithMessage('The asset is already published.'));
            Route::get('throttled', fn () => 'ok')->middleware('throttle:1,1');
            Route::get('unauthenticated', fn () => 'ok')->middleware('auth:api');
        });

        // CSRF verification is skipped in tests, so the mismatch is thrown directly.
        Route::middleware('web')->group(function () {
            Route::match(['post', 'put'], 'test/csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
        });
    }

    public function test_server_error_hides_internal_message_in_production(): void
    {
        $this->getJson('/api/test/server')
            ->assertStatus(500)
            ->assertExactJson([
                'message' => __('errors.server'),
                'status' => 500,
                'code' => ErrorCode::Internal->value,
            ]);
    }

    public function test_debug_mode_exposes_exception_details(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/test/server')
            ->assertStatus(500)
            ->assertJsonPath('message', 'SQLSTATE secret details')
            ->assertJsonPath('exception', RuntimeException::class)
            ->assertJsonStructure(['file', 'line', 'trace']);
    }

    public function test_authorization_failure_is_403_access_denied(): void
    {
        $this->getJson('/api/test/forbidden')
            ->assertForbidden()
            ->assertJsonPath('code', ErrorCode::AccessDenied->value)
            ->assertJsonPath('message', 'Not your asset.');
    }

    public function test_missing_model_is_404_without_model_name(): void
    {
        $this->getJson('/api/test/missing-model')
            ->assertNotFound()
            ->assertJsonPath('code', ErrorCode::NotFound->value)
            ->assertJsonPath('message', __('errors.not_found'));
    }

    public function test_unknown_api_route_is_404(): void
    {
        $this->getJson('/api/test/nope')
            ->assertNotFound()
            ->assertJsonPath('code', ErrorCode::NotFound->value);
    }

    public function test_validation_error_lists_field_errors(): void
    {
        $this->getJson('/api/test/validation')
            ->assertUnprocessable()
            ->assertJsonPath('code', ErrorCode::Validation->value)
            ->assertJsonPath('errors.title.0', 'The title is required.');
    }

    public function test_exception_with_message_keeps_its_message_and_status(): void
    {
        $this->getJson('/api/test/with-message')
            ->assertStatus(400)
            ->assertJsonPath('code', ErrorCode::WithMessage->value)
            ->assertJsonPath('message', 'The asset is already published.');
    }

    public function test_throttled_request_reports_retry_after(): void
    {
        $this->getJson('/api/test/throttled')->assertOk();

        $response = $this->getJson('/api/test/throttled')
            ->assertTooManyRequests()
            ->assertJsonPath('code', ErrorCode::TooManyRequests->value)
            ->assertHeader('Retry-After');

        $this->assertGreaterThan(0, $response->json('retry_after'));
        $this->assertSame(__('errors.too_many_requests', ['seconds' => $response->json('retry_after')]), $response->json('message'));
    }

    public function test_unauthenticated_api_request_is_401(): void
    {
        $this->seed(OauthSeeder::class);

        $this->getJson('/api/test/unauthenticated')
            ->assertUnauthorized()
            ->assertJsonPath('code', ErrorCode::Unauthenticated->value)
            ->assertJsonPath('message', __('errors.unauthenticated'));
    }

    public function test_csrf_mismatch_on_json_request_is_419_with_csrf_code(): void
    {
        $this->postJson('/test/csrf')
            ->assertStatus(419)
            ->assertJsonPath('code', ErrorCode::Csrf->value)
            ->assertJsonPath('message', __('errors.session_expired'));
    }

    public function test_csrf_mismatch_on_inertia_visit_redirects_back_with_flash(): void
    {
        $this->from('/')
            ->post('/test/csrf', [], ['X-Inertia' => 'true'])
            ->assertRedirect('/')
            ->assertSessionHas('inertia.flash_data', ['error' => __('errors.page_expired')]);
    }

    public function test_csrf_mismatch_on_inertia_put_redirects_with_303(): void
    {
        $this->from('/')
            ->put('/test/csrf', [], ['X-Inertia' => 'true'])
            ->assertStatus(303)
            ->assertRedirect('/');
    }

    public function test_frontend_error_codes_match_the_enum(): void
    {
        preg_match('/ERROR_CODE = \{(.*?)\};/s', file_get_contents(resource_path('js/config/constant.js')), $block);
        preg_match_all('/(\w+):\s*(\d+)/', $block[1], $pairs, PREG_SET_ORDER);

        $frontend = collect($pairs)->mapWithKeys(fn ($pair) => [$pair[1] => (int) $pair[2]])->sortKeys()->all();
        $backend = collect(ErrorCode::cases())->mapWithKeys(fn (ErrorCode $code) => [strtoupper(Str::snake($code->name)) => $code->value])->sortKeys()->all();

        $this->assertSame($backend, $frontend);
    }
}
