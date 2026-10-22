<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\ApiTokenCookie;
use Database\Seeders\OauthSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        Notification::fake();
        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
    }

    /**
     * Fakes the Google answer with the fields of the v3 userinfo endpoint.
     *
     * @param  array<string, mixed>  $raw
     */
    protected function fakeGoogle(array $raw = []): void
    {
        $raw = array_merge([
            'sub' => '1234567890',
            'name' => 'Jane Doe',
            'given_name' => 'Jane',
            'family_name' => 'Doe',
            'email' => 'Jane.Doe@Gmail.com',
            'email_verified' => true,
            'picture' => 'https://lh3.googleusercontent.com/a/photo',
        ], $raw);

        Socialite::fake('google', (new GoogleUser)->setRaw($raw)->map([
            'id' => $raw['sub'],
            'name' => $raw['name'],
            'email' => $raw['email'],
            'avatar' => $raw['picture'],
        ]));
    }

    public function test_redirect_leaves_for_google(): void
    {
        $this->fakeGoogle();

        $this->get('/oauth/google/redirect')->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_routes_and_button_are_off_until_google_is_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/oauth/google/redirect')->assertNotFound();
        $this->get('/oauth/google/callback')->assertNotFound();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', false));
    }

    public function test_login_and_register_pages_show_the_button(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
        $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
    }

    public function test_new_user_with_a_verified_google_email_is_created_verified_and_logged_in(): void
    {
        Event::fake([Verified::class]);
        $this->fakeGoogle();

        $this->withSession(['url.intended' => url('/assets')])
            ->get('/oauth/google/callback')
            ->assertRedirect(url('/assets'))
            ->assertCookie(ApiTokenCookie::COOKIE);

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('jane.doe@gmail.com', $user->email);
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Doe', $user->last_name);
        $this->assertSame('jane.doe', $user->username);
        $this->assertSame('https://lh3.googleusercontent.com/a/photo', $user->avatar);
        $this->assertTrue($user->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
        Notification::assertNothingSent();
    }

    public function test_new_user_with_an_unverified_google_email_confirms_it_with_a_code(): void
    {
        $this->fakeGoogle(['email_verified' => false]);

        $this->get('/oauth/google/callback')->assertRedirect('/verify-email');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_existing_user_is_logged_in_without_changes(): void
    {
        $user = User::factory()->create(['email' => 'jane.doe@gmail.com', 'first_name' => 'Janet', 'avatar' => null]);
        $password = $user->password;
        $this->fakeGoogle();

        $this->get('/oauth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
        $this->assertSame('Janet', $user->fresh()->first_name);
        $this->assertSame($password, $user->fresh()->password);
    }

    public function test_existing_unverified_account_is_verified_and_its_password_is_reset(): void
    {
        // Someone registered the address before its owner and set a password.
        $user = User::factory()->unverified()->create(['email' => 'jane.doe@gmail.com']);
        $token = $user->createToken(ApiTokenCookie::TOKEN_NAME)->token;
        $this->fakeGoogle();

        $this->get('/oauth/google/callback')->assertRedirect('/');

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertTrue($token->fresh()->revoked);
    }

    public function test_existing_account_is_refused_when_google_does_not_vouch_for_the_email(): void
    {
        User::factory()->create(['email' => 'jane.doe@gmail.com']);
        $this->fakeGoogle(['email_verified' => false]);

        $this->get('/oauth/google/callback')
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['error' => __('auth.google.unverified_email')]);

        $this->assertGuest();
    }

    public function test_cancelled_consent_returns_to_login(): void
    {
        $this->get('/oauth/google/callback?error=access_denied')
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['error' => __('auth.google.cancelled')]);

        $this->assertGuest();
    }

    public function test_invalid_state_returns_to_login(): void
    {
        Socialite::fake('google', fn () => throw new InvalidStateException);

        $this->get('/oauth/google/callback?code=abc&state=stale')
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['error' => __('auth.google.failed')]);

        $this->assertGuest();
    }

    public function test_logged_in_user_is_not_sent_to_google(): void
    {
        $this->actingAs(User::factory()->create())->get('/oauth/google/redirect')->assertRedirect('/');
    }
}
