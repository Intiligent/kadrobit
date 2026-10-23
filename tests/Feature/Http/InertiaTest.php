<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_for_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home/index')
                ->where('auth.user', null)
                ->where('appName', config('app.name'))
                ->where('locale', 'en'));
    }

    public function test_authenticated_user_is_shared(): void
    {
        $user = User::factory()->create(['first_name' => 'Jane']);

        // The home page redirects logged-in users, so the shared props are checked on the library.
        $this->actingAs($user)
            ->get('/library')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $user->id)
                ->where('auth.user.first_name', 'Jane')
                ->where('auth.user.email_verified', true)
                ->missing('auth.user.password'));
    }

    public function test_csrf_refresh_route_renews_the_xsrf_cookie(): void
    {
        $this->get('/csrf-token')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }
}
