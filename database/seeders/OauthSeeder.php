<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Passport keys and the personal access client used to issue API tokens.
 * Safe to run repeatedly.
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
    }
}
