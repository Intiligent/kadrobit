<?php

namespace Tests\Feature\Auth;

use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class OauthSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_single_personal_access_client_when_run_repeatedly(): void
    {
        $this->seed(OauthSeeder::class);
        $this->seed(OauthSeeder::class);

        $clients = Client::all();

        $this->assertCount(1, $clients);
        $this->assertTrue($clients->first()->hasGrantType('personal_access'));
        $this->assertSame('users', $clients->first()->provider);
    }

    public function test_replaces_a_revoked_client(): void
    {
        $this->seed(OauthSeeder::class);
        Client::query()->update(['revoked' => true]);

        $this->seed(OauthSeeder::class);

        $this->assertSame(1, Client::where('revoked', false)->count());
    }
}
