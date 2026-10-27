<?php

namespace Tests\Feature;

use App\Enums\UserSettingKey;
use App\Models\User;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_lists_the_marketplaces_kadrobit_uploads_to(): void
    {
        $this->seed(MarketplaceSeeder::class);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home/index')
                ->where('marketplaces', fn ($marketplaces) => collect($marketplaces)->contains(
                    fn ($m) => $m['code'] === 'shutterstock' && $m['protocol'] === 'ftps',
                ) && ! collect($marketplaces)->contains('code', 'istock'))
                ->where('unsupported', ['Canva Creators', 'Getty Images', 'iStock']));
    }

    public function test_logged_in_users_go_to_their_library(): void
    {
        // A web login issues the API token cookie, which needs the personal access client.
        $this->seed(OauthSeeder::class);

        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/library');
    }

    public function test_a_guest_switches_the_language_for_the_session(): void
    {
        $this->post('/locale', ['locale' => 'ru'])->assertNoContent();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('locale', 'ru'));
    }

    public function test_a_logged_in_user_switches_their_setting(): void
    {
        $this->seed(OauthSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/locale', ['locale' => 'ru'])->assertNoContent();

        $this->assertSame('ru', $user->fresh()->setting(UserSettingKey::GeneralLocale));
    }

    public function test_unknown_languages_are_rejected(): void
    {
        $this->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
    }
}
