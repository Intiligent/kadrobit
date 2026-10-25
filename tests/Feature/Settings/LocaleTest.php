<?php

namespace Tests\Feature\Settings;

use App\Enums\UserSettingKey;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
        $this->user = User::factory()->create(['email' => 'jane@example.com']);
    }

    protected function russian(User $user): User
    {
        $user->setSetting(UserSettingKey::GeneralLocale, 'ru');

        return $user;
    }

    public function test_a_guest_gets_english(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_a_guest_gets_the_language_kept_in_the_session(): void
    {
        $this->withSession([SetLocale::SESSION_KEY => 'ru'])
            ->get('/login')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));

        $this->withSession([SetLocale::SESSION_KEY => 'xx'])
            ->get('/login')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_a_user_gets_the_language_of_their_setting_and_the_session_keeps_it(): void
    {
        $this->actingAs($this->russian($this->user), 'web')
            ->get('/library')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ru')->where('settings.general.locale', 'ru'))
            ->assertSessionHas(SetLocale::SESSION_KEY, 'ru');
    }

    public function test_the_setting_wins_over_the_session(): void
    {
        // The language was changed on another device, or the session belongs to someone who logged out.
        $this->actingAs($this->user, 'web')
            ->withSession([SetLocale::SESSION_KEY => 'ru'])
            ->get('/library')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'))
            ->assertSessionHas(SetLocale::SESSION_KEY, 'en');
    }

    public function test_pages_after_logout_stay_in_the_users_language(): void
    {
        $this->actingAs($this->russian($this->user), 'web')->get('/library');

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));
    }

    public function test_the_language_changes_through_the_api_and_applies_to_the_next_page(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/settings', ['general' => ['locale' => 'ru']])
            ->assertOk()
            ->assertJsonPath('data.general.locale', 'ru');

        $this->actingAs($this->user->fresh(), 'web')
            ->withSession([SetLocale::SESSION_KEY => 'en'])
            ->get('/library')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));
    }

    public function test_english_is_the_default_and_stores_no_row(): void
    {
        Passport::actingAs($this->russian($this->user));

        $this->patchJson('/api/settings', ['general' => ['locale' => 'en']])->assertJsonPath('data.general.locale', 'en');

        $this->assertSame(0, $this->user->settings()->count());
    }

    public function test_only_supported_languages_are_accepted(): void
    {
        Passport::actingAs($this->user);

        $this->patchJson('/api/settings', ['general' => ['locale' => 'de']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('general.locale');

        $this->patchJson('/api/settings', ['general' => ['locale' => ['ru']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('general.locale');
    }

    public function test_api_answers_in_the_users_language(): void
    {
        Passport::actingAs($this->russian($this->user));

        $this->patchJson('/api/settings', ['theme' => 'dark'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.theme.0', 'Такой настройки нет.');
    }

    public function test_validation_messages_of_the_framework_are_translated(): void
    {
        Passport::actingAs($this->russian($this->user));

        $this->patchJson('/api/settings', ['metadata' => ['autofill_on_upload' => 'maybe']])
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn (array $errors) => str_starts_with(reset($errors)[0], 'Поле «'));
    }

    public function test_the_user_prefers_the_language_of_their_setting(): void
    {
        $this->assertSame('en', $this->user->preferredLocale());
        $this->assertSame('ru', $this->russian($this->user)->preferredLocale());
    }

    public function test_an_email_is_sent_in_the_users_language_whatever_the_language_of_the_request(): void
    {
        $this->russian($this->user);

        // An English request: the guest asking for the link has no language in the session.
        $this->post('/forgot-password', ['email' => 'jane@example.com'])->assertRedirect();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('Смена пароля в '.config('app.name'), $email->getSubject());
        $this->assertStringContainsString('Ссылка действует 60 минут.', $email->getHtmlBody());
        $this->assertStringContainsString('<html lang="ru"', $email->getHtmlBody());
    }

    public function test_a_queued_notification_carries_the_users_language(): void
    {
        Notification::fake();

        $this->russian($this->user)->sendPasswordResetNotification('token');

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, fn ($notification, $channels, $notifiable, $locale) => $locale === 'ru');
    }
}
