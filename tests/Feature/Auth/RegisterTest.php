<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use Database\Seeders\OauthSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OauthSeeder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'username' => 'Jane.Doe',
            'email' => 'Jane@Example.com',
            'password' => 'secret-pass',
            ...$overrides,
        ];
    }

    public function test_register_page_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/register/index'));
    }

    public function test_user_registers_and_is_logged_in_with_a_token_cookie(): void
    {
        Event::fake([Registered::class]);

        $this->post('/register', $this->payload())
            ->assertRedirect('/verify-email')
            ->assertCookie(ApiTokenCookie::COOKIE, null, false);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('jane.doe', $user->username);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Event::assertDispatched(Registered::class);
    }

    public function test_registration_runs_the_real_registered_listeners(): void
    {
        $this->post('/register', $this->payload())->assertRedirect('/verify-email');

        $this->assertAuthenticated();
    }

    public function test_validation_errors(): void
    {
        $this->post('/register', $this->payload([
            'first_name' => '',
            'username' => 'a b',
            'email' => 'not-an-email',
            'password' => 'short',
        ]))->assertSessionHasErrors(['first_name', 'username', 'email', 'password']);

        $this->assertGuest();
    }

    public function test_username_and_email_must_be_unique_ignoring_case(): void
    {
        User::factory()->create(['username' => 'jane.doe', 'email' => 'jane@example.com']);

        $this->post('/register', $this->payload())
            ->assertSessionHasErrors(['username', 'email']);
    }

    public function test_throttled_inertia_submit_redirects_back_with_flash(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/register', $this->payload(['email' => 'bad']));
        }

        $this->from('/register')
            ->post('/register', $this->payload(), ['X-Inertia' => 'true'])
            ->assertRedirect('/register')
            ->assertSessionHas('inertia.flash_data.error');

        $this->assertGuest();
    }

    public function test_username_rule_message(): void
    {
        $this->post('/register', $this->payload(['username' => 'ab']))
            ->assertSessionHasErrors(['username' => __('auth.validation.username')]);
    }
}
