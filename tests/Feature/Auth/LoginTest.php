<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login/index'));
    }

    public function test_user_logs_in_and_gets_an_api_token_cookie(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->post('/login', ['email' => 'Jane@Example.com ', 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === ApiTokenCookie::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(1, Passport::token()->where('user_id', $user->id)->where('revoked', false)->count());

        // The raw cookie value is a usable bearer token.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$cookie->getValue())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_wrong_password_is_a_field_error(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->from('/login')
            ->post('/login', ['email' => 'jane@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_login_is_locked_after_too_many_failed_attempts(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->post('/login', ['email' => 'jane@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
    }

    public function test_redirect_parameter_returns_the_user_to_the_page(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->get('/login?redirect='.urlencode('/assets?page=2'));

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect(url('/assets?page=2'));
    }

    public function test_external_redirect_parameter_is_ignored(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->get('/login?redirect='.urlencode('//evil.test/phish'));

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertRedirect('/');
    }

    public function test_logged_in_user_is_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
    }

    public function test_guest_is_redirected_to_login_from_protected_pages(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }
}
