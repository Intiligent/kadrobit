<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\ApiTokenCookie;
use Database\Seeders\OauthSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        $this->user = User::factory()->create(['email' => 'jane@example.com', 'first_name' => 'Jane']);
    }

    protected function tokenFor(User $user): string
    {
        return Password::broker()->createToken($user);
    }

    /**
     * @return array<string, string>
     */
    protected function resetPayload(string $token, array $overrides = []): array
    {
        return [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
            ...$overrides,
        ];
    }

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password/index'));
    }

    public function test_reset_link_is_sent_to_an_existing_account(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'Jane@Example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('inertia.flash_data', ['success' => __('passwords.sent')]);

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function ($notification) {
            $url = $notification->toMail($this->user)->viewData['resetUrl'];

            return str_starts_with($url, route('password.reset', ['token' => $notification->token]))
                && str_contains($url, 'email=jane%40example.com');
        });
    }

    public function test_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data', ['success' => __('passwords.sent')]);

        Notification::assertNothingSent();
    }

    public function test_repeated_request_gets_the_same_answer(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'jane@example.com']);
        $this->post('/forgot-password', ['email' => 'jane@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data', ['success' => __('passwords.sent')]);

        // The broker throttles the second email for a minute.
        Notification::assertSentToTimes($this->user, ResetPasswordNotification::class, 1);
    }

    public function test_reset_email_goes_through_the_queue(): void
    {
        Queue::fake();

        $this->post('/forgot-password', ['email' => 'jane@example.com']);

        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof ResetPasswordNotification);
    }

    public function test_reset_email_is_queued_and_renders(): void
    {
        $notification = new ResetPasswordNotification('token-123');

        $this->assertInstanceOf(ShouldQueue::class, $notification);

        $html = $notification->toMail($this->user)->render();

        $this->assertStringContainsString(e(route('password.reset', ['token' => 'token-123', 'email' => 'jane@example.com'])), $html);
        $this->assertStringContainsString('Hi Jane,', $html);
        $this->assertStringContainsString(__('passwords.email.expire', ['count' => 60]), $html);
    }

    public function test_reset_page_renders_with_token_and_email(): void
    {
        $this->get('/reset-password/abc?email=jane@example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/reset-password/index')
                ->where('token', 'abc')
                ->where('email', 'jane@example.com'));
    }

    public function test_password_is_reset_with_a_valid_token(): void
    {
        $oldRememberToken = $this->user->remember_token;

        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user)))
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['success' => __('passwords.reset')]);

        $this->user->refresh();
        $this->assertTrue(Hash::check('new-secret-pass', $this->user->password));
        $this->assertNotSame($oldRememberToken, $this->user->remember_token);
        $this->assertGuest();

        // The token is single-use.
        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user), ['token' => 'used']))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
    }

    public function test_user_can_log_in_with_the_new_password(): void
    {
        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user)));

        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'new-secret-pass'])->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->tokenFor($this->user);

        $this->post('/reset-password', $this->resetPayload('wrong-token'))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->tokenFor($this->user);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->post('/reset-password', $this->resetPayload($token))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
    }

    public function test_unknown_email_on_reset_reads_like_an_invalid_link(): void
    {
        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user), ['email' => 'nobody@example.com']))
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
    }

    public function test_password_must_be_confirmed_and_long_enough(): void
    {
        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user), [
            'password' => 'short',
            'password_confirmation' => 'other',
        ]))->assertSessionHasErrors(['password']);
    }

    public function test_reset_revokes_web_session_tokens_but_not_other_tokens(): void
    {
        $this->user->createToken(ApiTokenCookie::TOKEN_NAME);
        $integration = $this->user->createToken('integration');

        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user)));

        $active = Passport::token()->where('user_id', $this->user->id)->where('revoked', false)->pluck('id');
        $this->assertSame([$integration->accessTokenId], $active->all());
    }

    public function test_sessions_on_other_devices_end_after_the_password_changes(): void
    {
        $this->post('/login', ['email' => 'jane@example.com', 'password' => 'password']);
        $this->get('/library')->assertOk();

        // The reset happens elsewhere; this session still carries the old password hash.
        $this->user->forceFill(['password' => 'changed-elsewhere'])->save();

        // Each real request loads the user afresh; the test app would reuse the cached one.
        $this->app['auth']->forgetGuards();

        $this->get('/library')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, Passport::token()->where('user_id', $this->user->id)->where('revoked', false)->count());
    }

    public function test_logged_in_user_can_use_the_reset_link(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->get('/reset-password/abc?email=jane@example.com')->assertOk();

        $this->post('/reset-password', $this->resetPayload($this->tokenFor($this->user)))
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['success' => __('passwords.reset')]);

        $this->assertGuest();
        $this->assertTrue(Hash::check('new-secret-pass', $this->user->fresh()->password));
    }

    public function test_auth_forms_have_separate_rate_limits(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', ['email' => 'bad']);
        }

        $this->post('/register', ['email' => 'bad'])->assertTooManyRequests();
        $this->post('/forgot-password', ['email' => 'jane@example.com'])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_forms_are_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => 'jane@example.com']);
        }

        $this->post('/forgot-password', ['email' => 'jane@example.com'])->assertTooManyRequests();
    }

    public function test_logged_in_user_is_redirected_away_from_the_request_form(): void
    {
        $this->actingAs($this->user)->get('/forgot-password')->assertRedirect('/');
    }
}
