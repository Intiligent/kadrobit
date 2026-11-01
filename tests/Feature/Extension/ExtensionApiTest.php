<?php

namespace Tests\Feature\Extension;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Enums\ModerationStatus;
use App\Http\Resources\Extension\ExtensionListingResource;
use App\Models\Asset;
use App\Models\AssetListing;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\User;
use App\Services\Extension\ExtensionTokens;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Tests\TestCase;

class ExtensionApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user, [config('extension.scope')]);
    }

    protected function account(string $code = 'shutterstock', array $attributes = [], ?User $user = null): MarketplaceAccount
    {
        $marketplace = Marketplace::where('code', $code)->sole();

        return ($user ?? $this->user)->marketplaceAccounts()->create([
            'marketplace_id' => $marketplace->id,
            'label' => $marketplace->name,
            'host' => $marketplace->upload_defaults['host'] ?? 'ftp.example.com',
            'login' => 'akadrov',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
            ...$attributes,
        ]);
    }

    protected function work(?User $user = null): Asset
    {
        static $n = 0;
        $n++;

        $asset = ($user ?? $this->user)->assets()->create([
            'type' => 'photo',
            'status' => AssetStatus::Ready,
            'title' => 'Glacier lagoon',
            'description' => 'Icebergs floating in a glacier lagoon at sunrise',
            'keywords' => ['glacier', 'lagoon', 'iceberg'],
            'categories' => ['shutterstock' => ['9', '8']],
            'original_filename' => "IMG_{$n}.jpg",
            'checksum' => hash('sha256', "work-{$n}"),
            'width' => 3000,
            'height' => 2000,
        ]);

        $asset->files()->create([
            'role' => AssetFileRole::Original,
            'disk' => AssetFileRole::Original->disk(),
            'path' => "assets/{$asset->ulid}/original",
            'mime' => 'image/jpeg',
            'size_bytes' => 1_000_000,
            'checksum' => $asset->checksum,
        ]);

        return $asset;
    }

    /**
     * A work on the account's server, with the metadata it was sent with.
     */
    protected function sent(MarketplaceAccount $account, array $attributes = [], ?Asset $asset = null): AssetListing
    {
        $asset ??= $this->work($account->user);

        return AssetListing::create([
            'asset_id' => $asset->id,
            'marketplace_account_id' => $account->id,
            'status' => ListingStatus::Sent,
            'remote_filename' => "{$asset->ulid}.jpg",
            'metadata' => AssetListing::snapshot($asset, $account->marketplace),
            'sent_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_me_lists_accounts_of_supported_marketplaces_with_counts(): void
    {
        $shutterstock = $this->account('shutterstock', ['settings' => ['auto_submit' => false]]);
        $adobe = $this->account('adobe_stock', ['contributor_id' => '123']);
        $this->account('alamy');

        $this->sent($shutterstock, ['moderation_status' => ModerationStatus::Pending, 'moderation_checked_at' => now()]);
        $this->sent($shutterstock, ['moderation_status' => ModerationStatus::Rejected]);
        $this->sent($adobe);

        $this->getJson('/api/extension/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $this->user->email)
            ->assertJsonPath('data.stats.sent', 3)
            ->assertJsonPath('data.stats.pending', 1)
            ->assertJsonPath('data.stats.rejected', 1)
            ->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.accounts.0.marketplace.code', 'shutterstock')
            ->assertJsonPath('data.accounts.0.auto_submit', false)
            ->assertJsonPath('data.accounts.0.stats.sent', 2)
            ->assertJsonPath('data.accounts.1.marketplace.code', 'adobe_stock')
            ->assertJsonPath('data.accounts.1.auto_submit', true)
            ->assertJsonPath('data.accounts.1.stats.pending', 0);
    }

    public function test_listings_are_the_users_sent_works_on_the_marketplace_with_category_names(): void
    {
        $account = $this->account();
        MarketplaceCategory::create(['marketplace_id' => $account->marketplace_id, 'external_id' => '9', 'name' => 'Objects']);

        $sent = $this->sent($account);
        $this->sent($account, ['status' => ListingStatus::Queued]);
        $this->sent($this->account('adobe_stock', ['contributor_id' => '123']));
        $this->sent($this->account(user: User::factory()->create()));

        $this->getJson('/api/extension/listings?marketplace=shutterstock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sent->id)
            ->assertJsonPath('data.0.remote_filename', $sent->remote_filename)
            ->assertJsonPath('data.0.outdated', false)
            ->assertJsonPath('data.0.metadata.categories', [['id' => '9', 'name' => 'Objects'], ['id' => '8', 'name' => '8']])
            ->assertJsonPath('data.0.metadata_hash', ExtensionListingResource::hash($sent->metadata));
    }

    public function test_listings_need_a_supported_marketplace(): void
    {
        $this->getJson('/api/extension/listings?marketplace=alamy')->assertUnprocessable();
        $this->getJson('/api/extension/listings')->assertUnprocessable();
    }

    public function test_report_saves_ids_review_status_and_synced_metadata(): void
    {
        $account = $this->account();
        $listing = $this->sent($account);
        $listing->asset->update(['title' => 'Edited title']);
        $hash = ExtensionListingResource::hash(AssetListing::snapshot($listing->asset->fresh(), $account->marketplace));

        $this->postJson('/api/extension/listings/report', ['listings' => [[
            'id' => $listing->id,
            'external_id' => 'U4217565405',
            'external_url' => 'https://submit.shutterstock.com/edit/U4217565405',
            'moderation_status' => 'pending',
            'metadata_hash' => $hash,
        ]]])->assertOk()->assertJsonPath('data.updated', 1);

        $listing->refresh();
        $this->assertSame('U4217565405', $listing->external_id);
        $this->assertSame('https://submit.shutterstock.com/edit/U4217565405', $listing->external_url);
        $this->assertSame(ModerationStatus::Pending, $listing->moderation_status);
        $this->assertNotNull($listing->moderation_checked_at);
        $this->assertSame('Edited title', $listing->metadata['title']);
        $this->assertFalse($listing->isOutdated());
    }

    public function test_report_keeps_a_work_edited_meanwhile_outdated(): void
    {
        $account = $this->account();
        $listing = $this->sent($account);
        $hash = ExtensionListingResource::hash($listing->metadata);
        $listing->asset->update(['title' => 'Edited after the extension read it']);

        $this->postJson('/api/extension/listings/report', ['listings' => [['id' => $listing->id, 'metadata_hash' => $hash]]])
            ->assertOk();

        $this->assertTrue($listing->fresh()->isOutdated());
    }

    public function test_report_skips_works_of_other_users_and_works_not_sent(): void
    {
        $foreign = $this->sent($this->account(user: User::factory()->create()));
        $queued = $this->sent($this->account(), ['status' => ListingStatus::Queued]);

        $this->postJson('/api/extension/listings/report', ['listings' => [
            ['id' => $foreign->id, 'external_id' => 'X1'],
            ['id' => $queued->id, 'external_id' => 'X2'],
        ]])->assertOk()->assertJsonPath('data.updated', 0);

        $this->assertNull($foreign->fresh()->external_id);
        $this->assertNull($queued->fresh()->external_id);
    }

    public function test_extension_tokens_are_turned_away_from_the_rest_of_the_api(): void
    {
        $this->getJson('/api/settings')->assertForbidden();
        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_other_tokens_cannot_use_the_extension_api(): void
    {
        Passport::actingAs($this->user);

        $this->getJson('/api/extension/me')->assertForbidden();
        $this->getJson('/api/settings')->assertOk();
    }

    public function test_personal_token_lasts_its_own_days_and_leaves_web_tokens_alone(): void
    {
        $tokens = app(ExtensionTokens::class);
        $result = $tokens->issue($this->user);

        $this->assertTrue($result->getToken()->expires_at->between(now()->addDays(364), now()->addDays(366)));
        $this->assertSame([config('extension.scope')], $result->getToken()->scopes);

        $web = $this->user->createToken('web');
        $this->assertTrue($web->getToken()->expires_at->lessThan(now()->addDay()));
    }

    public function test_logout_revokes_the_token_it_is_called_with(): void
    {
        $result = app(ExtensionTokens::class)->issue($this->user);
        $this->app['auth']->forgetGuards();

        $this->withToken($result->accessToken)->postJson('/api/extension/logout')->assertNoContent();

        $this->assertTrue(Token::find($result->accessTokenId)->revoked);
    }
}
