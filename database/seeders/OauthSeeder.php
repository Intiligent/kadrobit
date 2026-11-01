<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Passport keys, the personal access client used to issue API tokens and the browser extension's
 * OAuth client. Safe to run repeatedly.
 */
class OauthSeeder extends Seeder
{
    public function run(ClientRepository $clients): void
    {
        // Never overwrite existing keys: that would invalidate all issued tokens.
        if (! config('passport.private_key') && ! file_exists(Passport::keyPath('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }

        $provider = config('auth.guards.api.provider');

        $client = Client::query()
            ->where('revoked', false)
            ->where('provider', $provider)
            ->latest()
            ->get()
            ->first(fn (Client $client) => $client->hasGrantType('personal_access'));

        $client ??= $clients->createPersonalAccessGrantClient(config('app.name'), $provider);

        $this->command?->info("Personal access client: {$client->getKey()} ({$client->name})");

        $this->seedExtensionClient($provider);
    }

    /**
     * The browser extension's public client (Authorization Code with PKCE, no secret) under the fixed ID
     * the extension is built with. The redirect URI follows the extension ID, which the manifest key pins.
     */
    protected function seedExtensionClient(string $provider): void
    {
        $client = Client::query()->findOrNew(config('extension.client_id'));

        $client->forceFill([
            'id' => config('extension.client_id'),
            'name' => config('app.name').' Extension',
            'secret' => null,
            'provider' => $provider,
            'redirect_uris' => ['https://'.config('extension.id').'.chromiumapp.org/'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ])->save();

        $this->command?->info("Extension client: {$client->getKey()} ({$client->name})");
    }
}
