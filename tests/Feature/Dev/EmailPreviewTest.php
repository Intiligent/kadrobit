<?php

namespace Tests\Feature\Dev;

use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
    }

    public function test_previews_are_hidden_outside_the_local_environment(): void
    {
        $this->get('/dev/emails')->assertNotFound();
        $this->get('/dev/emails/reset-password')->assertNotFound();
    }

    public function test_index_lists_every_preview(): void
    {
        $this->local();

        $this->get('/dev/emails')
            ->assertOk()
            ->assertSee(route('dev.emails.reset-password'), false)
            ->assertSee('Reset Password');
    }

    public function test_reset_password_preview_renders_the_real_email_without_saving_anything(): void
    {
        $this->local();

        $this->get('/dev/emails/reset-password')
            ->assertOk()
            ->assertSee('Hi Jane,')
            ->assertSee(e(route('password.reset', ['token' => str_repeat('0', 64), 'email' => 'jane@example.com'])), false);

        $this->assertSame(0, User::count());
    }

    public function test_preview_renders_the_email_in_russian(): void
    {
        $this->local();

        $this->get('/dev/emails/reset-password?locale=ru')
            ->assertOk()
            ->assertSee('Здравствуйте, Jane!')
            ->assertSee('Ссылка действует 60 минут.');

        $this->get('/dev/emails/verify-email?locale=ru')
            ->assertOk()
            ->assertSee('Код действует 15 минут.');
    }

    public function test_preview_accepts_only_existing_locales(): void
    {
        $this->local();

        $this->get('/dev/emails/reset-password?locale=en')->assertOk();
        $this->get('/dev/emails/reset-password?locale=../en')->assertNotFound();
        $this->get('/dev/emails/reset-password?locale=xx')->assertNotFound();
    }

    public function test_preview_uses_the_logged_in_user(): void
    {
        $this->local();
        $this->seed(OauthSeeder::class);

        $this->actingAs(User::factory()->create(['first_name' => 'Vitaliy']))
            ->get('/dev/emails/reset-password')
            ->assertSee('Hi Vitaliy,');
    }

    public function test_verify_email_preview_link_does_not_verify_anyone(): void
    {
        $this->local();
        $this->seed(OauthSeeder::class);
        $user = User::factory()->unverified()->create();

        $html = $this->actingAs($user)->get('/dev/emails/verify-email')->assertOk()->assertSee('123456')->getContent();

        preg_match('~href="([^"]*/verify-email/'.$user->id.'/[^"]*)"~', $html, $match);
        $this->assertNotEmpty($match);

        $this->get(html_entity_decode($match[1]))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
