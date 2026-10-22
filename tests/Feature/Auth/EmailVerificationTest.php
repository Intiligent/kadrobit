<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\EmailVerificationService;
use Database\Seeders\OauthSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        Notification::fake();
        $this->user = User::factory()->unverified()->create(['email' => 'jane@example.com', 'first_name' => 'Jane']);
    }

    protected function service(): EmailVerificationService
    {
        return $this->app->make(EmailVerificationService::class);
    }

    /**
     * Issues a code and returns it, read from the queued notification.
     */
    protected function issueCode(): string
    {
        $this->service()->issue($this->user);

        return $this->sentCodes()[array_key_last($this->sentCodes())];
    }

    /**
     * Codes of all confirmation emails sent to the user, oldest first.
     *
     * @return list<string>
     */
    protected function sentCodes(): array
    {
        return Notification::sent($this->user, VerifyEmailNotification::class)
            ->map(fn (VerifyEmailNotification $notification) => $notification->code)
            ->values()
            ->all();
    }

    /**
     * Issues a code and returns the link from the same email.
     */
    protected function issueLink(): string
    {
        $this->service()->issue($this->user);

        return Notification::sent($this->user, VerifyEmailNotification::class)->last()->verifyUrl;
    }

    protected function wrongCode(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }

    public function test_registration_sends_a_code_and_opens_the_confirmation_screen(): void
    {
        $this->post('/register', [
            'first_name' => 'Ann', 'username' => 'ann', 'email' => 'ann@example.com', 'password' => 'secret-pass',
        ])->assertRedirect('/verify-email');

        $user = User::where('email', 'ann@example.com')->sole();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertSame(1, EmailVerificationCode::where('user_id', $user->id)->count());
    }

    public function test_notice_page_renders_for_an_unverified_user(): void
    {
        $this->issueCode();

        $this->actingAs($this->user)->get('/verify-email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/verify-email/index')
                ->where('email', 'jane@example.com')
                ->where('codeLength', 6)
                ->where('cooldown', fn ($value) => $value > 55 && $value <= 60));
    }

    public function test_verified_user_is_sent_home_from_the_notice(): void
    {
        $this->actingAs(User::factory()->create())->get('/verify-email')->assertRedirect('/');
    }

    public function test_code_is_stored_only_as_a_hash(): void
    {
        $code = $this->issueCode();

        $record = EmailVerificationCode::sole();
        $this->assertNotSame($code, $record->code_hash);
        $this->assertSame(64, strlen($record->code_hash));
        $this->assertSame('jane@example.com', $record->email);
    }

    public function test_correct_code_verifies_the_email(): void
    {
        Event::fake([Verified::class]);
        $code = $this->issueCode();

        $this->actingAs($this->user)
            ->post('/verify-email', ['code' => substr($code, 0, 3).' '.substr($code, 3)])
            ->assertRedirect('/')
            ->assertSessionHas('inertia.flash_data', ['success' => __('verification.flash.verified')]);

        $this->assertTrue($this->user->fresh()->hasVerifiedEmail());
        $this->assertSame(0, EmailVerificationCode::count());
        Event::assertDispatched(Verified::class);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $code = $this->issueCode();

        $this->actingAs($this->user)
            ->post('/verify-email', ['code' => $this->wrongCode($code)])
            ->assertSessionHasErrors(['code' => __('verification.errors.invalid')]);

        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
        $this->assertSame(1, EmailVerificationCode::sole()->attempts);
    }

    public function test_code_is_cancelled_after_too_many_wrong_attempts(): void
    {
        $code = $this->issueCode();
        $this->actingAs($this->user);

        for ($i = 1; $i < EmailVerificationService::MAX_ATTEMPTS; $i++) {
            $this->post('/verify-email', ['code' => $this->wrongCode($code)])
                ->assertSessionHasErrors(['code' => __('verification.errors.invalid')]);
        }

        $this->post('/verify-email', ['code' => $this->wrongCode($code)])
            ->assertSessionHasErrors(['code' => __('verification.errors.too_many_attempts')]);

        // Even the right code no longer works.
        $this->post('/verify-email', ['code' => $code])
            ->assertSessionHasErrors(['code' => __('verification.errors.missing')]);
        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_attempts_are_counted_before_the_check(): void
    {
        $code = $this->issueCode();
        EmailVerificationCode::query()->update(['attempts' => EmailVerificationService::MAX_ATTEMPTS]);

        // A parallel request that already used the last attempt: the right code is refused too.
        $this->assertSame(EmailVerificationService::RESULT_TOO_MANY_ATTEMPTS, $this->service()->verify($this->user, $code));
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->issueCode();

        $this->travel(EmailVerificationService::CODE_TTL_MINUTES + 1)->minutes();

        $this->actingAs($this->user)
            ->post('/verify-email', ['code' => $code])
            ->assertSessionHasErrors(['code' => __('verification.errors.expired')]);
    }

    public function test_code_must_be_six_digits(): void
    {
        $this->actingAs($this->user)->post('/verify-email', ['code' => '12ab'])->assertSessionHasErrors('code');
    }

    public function test_resend_issues_a_new_code_and_cancels_the_old_one(): void
    {
        $old = $this->issueCode();
        $this->travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->actingAs($this->user)
            ->post('/verify-email/resend')
            ->assertSessionHas('inertia.flash_data', ['success' => __('verification.flash.resent')]);

        [, $new] = $this->sentCodes();
        $this->assertSame(1, EmailVerificationCode::count());

        // The old code stops working (unless the random new one happens to be the same).
        if ($old !== $new) {
            $this->assertSame(EmailVerificationService::RESULT_INVALID, $this->service()->verify($this->user, $old));
        }
        $this->assertSame(EmailVerificationService::RESULT_OK, $this->service()->verify($this->user, $new));
    }

    public function test_resend_respects_the_cooldown(): void
    {
        $this->issueCode();

        $this->actingAs($this->user)
            ->post('/verify-email/resend')
            ->assertSessionHasErrors('code');

        Notification::assertSentToTimes($this->user, VerifyEmailNotification::class, 1);
    }

    public function test_resend_respects_the_daily_limit(): void
    {
        for ($i = 0; $i < EmailVerificationService::DAILY_EMAIL_LIMIT; $i++) {
            $this->service()->issue($this->user);
        }
        $this->travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();

        $this->actingAs($this->user)
            ->post('/verify-email/resend')
            ->assertSessionHasErrors(['code' => __('verification.errors.daily_limit')]);
    }

    public function test_signed_link_verifies_without_a_login(): void
    {
        $url = $this->issueLink();

        $this->get($url)
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['success' => __('verification.flash.verified')]);

        $this->assertTrue($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_signed_link_returns_a_logged_in_user_to_the_intended_page(): void
    {
        $this->actingAs($this->user)->withSession(['url.intended' => url('/assets')]);

        $this->get($this->issueLink())->assertRedirect(url('/assets'));
    }

    public function test_tampered_link_is_rejected(): void
    {
        $url = $this->issueLink();

        $this->get($url.'x')->assertForbidden();
        $this->get(str_replace('/'.$this->user->id.'/', '/'.($this->user->id + 1).'/', $url))->assertForbidden();
        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_link_for_a_previous_address_is_rejected(): void
    {
        $url = $this->issueLink();
        $this->user->forceFill(['email' => 'new@example.com'])->save();

        $this->get($url)->assertForbidden();
    }

    public function test_expired_link_is_rejected(): void
    {
        $url = $this->issueLink();

        $this->travel(EmailVerificationService::CODE_TTL_MINUTES + 1)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_email_can_be_changed_while_unverified(): void
    {
        $old = $this->issueCode();

        $this->actingAs($this->user)
            ->put('/verify-email/email', ['email' => 'Jane.New@Example.com'])
            ->assertSessionHas('inertia.flash_data', ['success' => __('verification.flash.email_updated')]);

        $user = $this->user->fresh();
        [, $new] = $this->sentCodes();

        $this->assertSame('jane.new@example.com', $user->email);
        $this->assertSame('jane.new@example.com', EmailVerificationCode::sole()->email);

        if ($old !== $new) {
            $this->assertSame(EmailVerificationService::RESULT_INVALID, $this->service()->verify($user, $old));
        }
        $this->assertSame(EmailVerificationService::RESULT_OK, $this->service()->verify($user, $new));
    }

    public function test_code_sent_to_a_previous_address_is_not_accepted(): void
    {
        $code = $this->issueCode();
        $this->user->forceFill(['email' => 'other@example.com'])->save();

        $this->assertSame(EmailVerificationService::RESULT_MISSING, $this->service()->verify($this->user, $code));
    }

    public function test_changed_email_must_be_available_and_the_message_is_neutral(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->user)
            ->put('/verify-email/email', ['email' => 'taken@example.com'])
            ->assertSessionHasErrors(['email' => __('verification.errors.email_unavailable')]);
    }

    public function test_verified_middleware_sends_unverified_users_to_the_notice(): void
    {
        Route::middleware(['web', 'auth', 'verified'])->get('test/verified-only', fn () => 'ok');

        $this->actingAs($this->user)->get('/test/verified-only')->assertRedirect('/verify-email');
        $this->actingAs(User::factory()->create())->get('/test/verified-only')->assertOk();
    }

    public function test_code_email_is_queued_encrypted_and_renders(): void
    {
        $notification = new VerifyEmailNotification('123456', URL::to('/verify-email/1/hash'), 1);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);

        $mail = $notification->toMail($this->user);
        $this->assertStringStartsWith('123456', $mail->subject);
        $this->assertStringContainsString('123456', (string) $mail->render());
    }

    public function test_link_dies_with_its_code_after_a_resend(): void
    {
        $url = $this->issueLink();
        $this->travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->service()->issue($this->user);

        $this->actingAs($this->user)->get($url)
            ->assertRedirect('/verify-email')
            ->assertSessionHas('inertia.flash_data', ['error' => __('verification.errors.link_expired')]);

        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_link_dies_when_its_code_burns_out(): void
    {
        $url = $this->issueLink();
        EmailVerificationCode::query()->update(['attempts' => EmailVerificationService::MAX_ATTEMPTS]);

        $this->get($url)
            ->assertRedirect('/login')
            ->assertSessionHas('inertia.flash_data', ['error' => __('verification.errors.link_expired')]);

        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_link_has_its_own_rate_limit(): void
    {
        $code = $this->issueCode();
        $url = Notification::sent($this->user, VerifyEmailNotification::class)->last()->verifyUrl;
        $this->actingAs($this->user);

        // Burns the code-attempt bucket (new codes keep the attempts below the per-code limit).
        for ($i = 0; $i < 10; $i++) {
            EmailVerificationCode::query()->update(['attempts' => 0]);
            $this->post('/verify-email', ['code' => $this->wrongCode($code)]);
        }
        $this->post('/verify-email', ['code' => $code])->assertTooManyRequests();

        EmailVerificationCode::query()->update(['attempts' => 0]);
        $this->get($url)->assertRedirect('/');
        $this->assertTrue($this->user->fresh()->hasVerifiedEmail());
    }

    public function test_queued_email_is_dropped_once_its_code_is_replaced(): void
    {
        $this->service()->issue($this->user);
        $first = Notification::sent($this->user, VerifyEmailNotification::class)->last();

        $this->assertTrue($first->shouldSend($this->user, 'mail'));

        // The address changed while the email waited in the queue: the worker reloads the user.
        $this->user->forceFill(['email' => 'new@example.com'])->save();
        $this->service()->issue($this->user);
        $second = Notification::sent($this->user, VerifyEmailNotification::class)->last();

        $this->assertFalse($first->shouldSend($this->user, 'mail'));
        $this->assertTrue($second->shouldSend($this->user, 'mail'));
    }

    public function test_parallel_send_is_refused_while_another_holds_the_lock(): void
    {
        $this->travel(EmailVerificationService::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $lock = Cache::lock('email-verification:send:'.$this->user->id, 10);
        $lock->get();

        $this->actingAs($this->user)
            ->post('/verify-email/resend')
            ->assertSessionHasErrors('code');

        Notification::assertNothingSentTo($this->user);
        $lock->release();
    }

    public function test_account_is_marked_verified_once(): void
    {
        Event::fake([Verified::class]);

        // Two requests holding their own copy of the unverified user.
        $other = User::find($this->user->id);

        $this->assertTrue($this->service()->markVerified($this->user));
        $this->assertFalse($this->service()->markVerified($other));
        $this->assertTrue($this->user->hasVerifiedEmail());
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_previous_address_cannot_be_marked_verified(): void
    {
        $stale = User::find($this->user->id);
        $this->user->forceFill(['email' => 'new@example.com'])->save();

        $this->assertFalse($this->service()->markVerified($stale));
        $this->assertFalse($this->user->fresh()->hasVerifiedEmail());
    }
}
