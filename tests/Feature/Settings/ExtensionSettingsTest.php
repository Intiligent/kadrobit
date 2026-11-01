<?php

namespace Tests\Feature\Settings;

use App\Enums\MarketplaceAccountStatus;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Extension\ExtensionTokens;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\TestCase;

class ExtensionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        // The mechanism for several marketplaces, as once their adapters are there (config/extension.php).
        config(['extension.marketplaces' => ['shutterstock', '123rf', 'adobe_stock']]);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function account(string $code): MarketplaceAccount
    {
        $marketplace = Marketplace::where('code', $code)->sole();

        return $this->user->marketplaceAccounts()->create([
            'marketplace_id' => $marketplace->id,
            'label' => $marketplace->name,
            'host' => 'ftp.example.com',
            'login' => 'akadrov',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
        ]);
    }

    public function test_page_shows_the_release_and_connected_extensions(): void
    {
        $path = storage_path('framework/testing/release.json');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(['version' => '0.1.0', 'built_at' => '2026-11-01T12:00:00+00:00', 'file' => 'kadrobit-extension-0.1.0.zip']));
        config(['extension.release_path' => $path]);

        app(ExtensionTokens::class)->issue($this->user);
        $this->user->createToken('web');

        $this->actingAs($this->user, 'web')->get('/settings/extension')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/extension/index')
                ->where('release.version', '0.1.0')
                ->where('release.url', asset('extension/kadrobit-extension-0.1.0.zip'))
                ->where('extensionId', config('extension.id'))
                ->has('connections', 1)
                ->where('connections.0.kind', 'personal'));

        @unlink($path);
    }

    public function test_page_without_a_built_release(): void
    {
        config(['extension.release_path' => storage_path('framework/testing/missing.json')]);

        $this->actingAs($this->user, 'web')->get('/settings/extension')
            ->assertInertia(fn (Assert $page) => $page->where('release', null)->has('connections', 0));
    }

    public function test_issues_a_personal_token_shown_once(): void
    {
        $response = $this->postJson('/api/extension-tokens')
            ->assertCreated()
            ->assertJsonPath('data.kind', 'personal');

        $this->assertNotEmpty($response->json('token'));
        $this->assertCount(1, app(ExtensionTokens::class)->connections($this->user));
    }

    public function test_disconnects_an_extension(): void
    {
        $result = app(ExtensionTokens::class)->issue($this->user);

        $this->deleteJson("/api/extension-tokens/{$result->accessTokenId}")->assertNoContent();

        $this->assertTrue(Token::find($result->accessTokenId)->revoked);
    }

    public function test_cannot_disconnect_other_tokens(): void
    {
        $foreign = app(ExtensionTokens::class)->issue(User::factory()->create());
        $web = $this->user->createToken('web');

        $this->deleteJson("/api/extension-tokens/{$foreign->accessTokenId}")->assertNotFound();
        $this->deleteJson("/api/extension-tokens/{$web->accessTokenId}")->assertNotFound();

        $this->assertFalse(Token::find($foreign->accessTokenId)->revoked);
        $this->assertFalse(Token::find($web->accessTokenId)->revoked);
    }

    public function test_password_reset_disconnects_the_extension(): void
    {
        $result = app(ExtensionTokens::class)->issue($this->user);

        event(new PasswordReset($this->user));

        $this->assertTrue(Token::find($result->accessTokenId)->revoked);
    }

    public function test_auto_submit_is_on_by_default_and_switched_per_account(): void
    {
        $account = $this->account('shutterstock');

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['auto_submit' => false])
            ->assertOk()
            ->assertJsonPath('data.auto_submit', false);

        $this->assertFalse($account->fresh()->autoSubmits());
        $this->assertTrue($this->account('123rf')->autoSubmits());
    }

    public function test_auto_submit_only_where_the_extension_works(): void
    {
        $account = $this->account('alamy');

        $this->patchJson("/api/marketplace-accounts/{$account->id}", ['auto_submit' => true])->assertUnprocessable();

        $this->actingAs($this->user, 'web')->get('/settings/marketplaces')
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.auto_submit', null));
    }

    public function test_consent_page_for_the_extension_client(): void
    {
        $verifier = Str::random(64);

        $this->actingAs($this->user, 'web')->get('/oauth/authorize?'.http_build_query([
            'client_id' => config('extension.client_id'),
            'redirect_uri' => 'https://'.config('extension.id').'.chromiumapp.org/',
            'response_type' => 'code',
            'scope' => config('extension.scope'),
            'state' => 'state-1',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/authorize/index')
                ->where('client.id', config('extension.client_id'))
                ->where('scopes', [config('extension.scope')])
                ->where('state', 'state-1'));
    }
}
