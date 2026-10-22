<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The passport guard needs the keys and personal access client even for guests.
        $this->seed(OauthSeeder::class);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_acting_user_gets_own_profile(): void
    {
        $user = User::factory()->create(['username' => 'Jane_Doe']);

        Passport::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'username' => 'jane_doe',
                'email' => $user->email,
                'avatar' => null,
                'email_verified' => true,
            ]]);
    }

    public function test_personal_access_token_authenticates_requests(): void
    {
        $user = User::factory()->create();

        $result = $user->createToken('API Token');

        $this->assertEqualsWithDelta(
            now()->addDay()->timestamp,
            $result->token->expires_at->timestamp,
            60,
        );

        $this->withToken($result->accessToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $result = $user->createToken('API Token');

        $result->token->revoke();

        $this->withToken($result->accessToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }
}
