<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class ApiTokenCookieTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        $this->user = User::factory()->create(['email' => 'jane@example.com']);
    }

    protected function login(): Cookie
    {
        $response = $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password']);

        return $this->tokenCookie($response);
    }

    protected function tokenCookie($response): ?Cookie
    {
        return collect($response->headers->getCookies())->first(fn (Cookie $c) => $c->getName() === ApiTokenCookie::COOKIE);
    }

    protected function activeTokens(): int
    {
        return Passport::token()->where('user_id', $this->user->id)->where('revoked', false)->count();
    }

    public function test_api_request_is_authenticated_by_the_cookie_alone(): void
    {
        $cookie = $this->login();
        $this->app['auth']->forgetGuards();

        // JSON test requests carry cookies only with credentials, like a same-origin browser request.
        $this->withCredentials()
            ->withUnencryptedCookie(ApiTokenCookie::COOKIE, $cookie->getValue())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id);
    }

    public function test_api_request_without_cookie_is_rejected(): void
    {
        $this->withCredentials()->getJson('/api/user')->assertUnauthorized();
    }

    public function test_revoked_token_cookie_is_rejected(): void
    {
        $cookie = $this->login();
        $this->post('/logout');
        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withUnencryptedCookie(ApiTokenCookie::COOKIE, $cookie->getValue())
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_sessions_token(): void
    {
        $otherDevice = $this->user->createToken('other device');
        $this->login();

        $response = $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame(1, $this->activeTokens());
        $this->assertFalse($otherDevice->getToken()->fresh()->revoked);
        $this->assertTrue($this->tokenCookie($response)->isCleared());
    }

    public function test_logout_requires_post(): void
    {
        $this->login();

        $this->get('/logout')->assertMethodNotAllowed();
    }

    public function test_token_is_renewed_before_it_expires_while_the_session_is_active(): void
    {
        $first = $this->login();

        // Far from expiry: no renewal.
        $this->withUnencryptedCookie(ApiTokenCookie::COOKIE, $first->getValue())->get('/');
        $this->assertSame(1, $this->activeTokens());

        // Inside the renewal window, while the session is still alive.
        $this->travel(config('session.lifetime') - 4)->minutes();

        $response = $this->withUnencryptedCookie(ApiTokenCookie::COOKIE, $first->getValue())->get('/');
        $renewed = $this->tokenCookie($response);

        $this->assertNotNull($renewed);
        $this->assertNotSame($first->getValue(), $renewed->getValue());
        $this->assertSame(1, $this->activeTokens());

        $this->app['auth']->forgetGuards();
        $this->withCredentials()
            ->withUnencryptedCookie(ApiTokenCookie::COOKIE, $renewed->getValue())
            ->getJson('/api/user')
            ->assertOk();
    }

    public function test_token_and_cookie_live_as_long_as_the_session(): void
    {
        // Passport checks the JWT expiry against the system clock, so it is asserted, not travelled past.
        $cookie = $this->login();
        $expected = now()->addMinutes(config('session.lifetime'))->getTimestamp();

        $token = Passport::token()->where('user_id', $this->user->id)->where('revoked', false)->sole();

        $this->assertEqualsWithDelta($expected, $token->expires_at->getTimestamp(), 60);
        $this->assertEqualsWithDelta($expected, $cookie->getExpiresTime(), 60);
    }

    public function test_foreign_cookie_is_replaced_with_the_sessions_token(): void
    {
        $this->login();
        $foreign = $this->user->createToken('leaked')->accessToken;

        $response = $this->withUnencryptedCookie(ApiTokenCookie::COOKIE, $foreign)->get('/');

        $renewed = $this->tokenCookie($response);
        $this->assertNotNull($renewed);
        $this->assertNotSame($foreign, $renewed->getValue());
    }

    public function test_renewal_of_a_session_is_claimed_once(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);
        $tokens = $this->app->make(ApiTokenCookie::class);

        $this->assertTrue($tokens->claimRenewal($request));
        $this->assertFalse($tokens->claimRenewal($request));
    }

    public function test_no_renewal_while_another_request_renews(): void
    {
        $this->login();
        $this->partialMock(ApiTokenCookie::class, fn ($mock) => $mock->shouldReceive('claimRenewal')->andReturn(false));

        $response = $this->get('/');

        $this->assertNull($this->tokenCookie($response));
        $this->assertSame(1, $this->activeTokens());
    }

    public function test_token_endpoint_issues_exactly_one_token(): void
    {
        $this->login();
        $before = Passport::token()->count();

        // No cookie: the renewal middleware must leave this route to the controller.
        $this->postJson('/auth/token')->assertNoContent();

        $this->assertSame($before + 1, Passport::token()->count());
    }

    public function test_logout_does_not_issue_a_token(): void
    {
        $this->login();
        $before = Passport::token()->count();

        $this->post('/logout');

        $this->assertSame($before, Passport::token()->count());
    }

    public function test_changing_api_request_with_cookie_requires_ajax_header(): void
    {
        Route::middleware(['api', 'auth:api'])->post('api/test/change', fn () => response()->json(['ok' => true]));
        $cookie = $this->login();
        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withUnencryptedCookie(ApiTokenCookie::COOKIE, $cookie->getValue())
            ->postJson('/api/test/change')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withUnencryptedCookie(ApiTokenCookie::COOKIE, $cookie->getValue())
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->postJson('/api/test/change')
            ->assertOk();
    }

    public function test_login_outside_an_http_session_does_not_fail(): void
    {
        Auth::guard('web')->login($this->user);

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(0, $this->activeTokens());
    }

    public function test_missing_cookie_is_reissued_for_a_logged_in_session(): void
    {
        $this->login();

        $response = $this->get('/');

        $this->assertNotNull($this->tokenCookie($response));
        $this->assertSame(1, $this->activeTokens());
    }

    public function test_token_endpoint_issues_a_new_token_and_revokes_the_old_one(): void
    {
        $first = $this->login();

        $response = $this->withCredentials()
            ->withUnencryptedCookie(ApiTokenCookie::COOKIE, $first->getValue())
            ->postJson('/auth/token')
            ->assertNoContent();

        $this->assertNotSame($first->getValue(), $this->tokenCookie($response)->getValue());
        $this->assertSame(1, $this->activeTokens());
    }

    public function test_token_endpoint_requires_a_session(): void
    {
        $this->postJson('/auth/token')->assertUnauthorized();
    }

    public function test_guest_pages_do_not_issue_tokens(): void
    {
        $response = $this->get('/');

        $this->assertNull($this->tokenCookie($response));
    }
}
