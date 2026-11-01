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

        $clients = Client::all()->filter(fn (Client $client) => $client->hasGrantType('personal_access'));

        $this->assertCount(1, $clients);
        $this->assertSame('users', $clients->first()->provider);
    }

    public function test_replaces_a_revoked_client(): void
    {
        $this->seed(OauthSeeder::class);
        Client::query()->update(['revoked' => true]);

        $this->seed(OauthSeeder::class);

        $this->assertSame(1, Client::where('revoked', false)->get()->filter(fn (Client $client) => $client->hasGrantType('personal_access'))->count());
    }

    public function test_creates_the_extension_client_under_its_fixed_id(): void
    {
        $this->seed(OauthSeeder::class);
        Client::query()->whereKey(config('extension.client_id'))->update(['revoked' => true]);
        $this->seed(OauthSeeder::class);

        $client = Client::findOrFail(config('extension.client_id'));

        $this->assertFalse($client->revoked);
        $this->assertFalse($client->confidential());
        $this->assertSame(['https://'.config('extension.id').'.chromiumapp.org/'], $client->redirect_uris);
        $this->assertTrue($client->hasGrantType('authorization_code'));
        $this->assertTrue($client->hasGrantType('refresh_token'));
    }
}
