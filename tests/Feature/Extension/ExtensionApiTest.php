<?php

namespace Tests\Feature\Extension;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Enums\ModerationStatus;
use App\Enums\UserSettingKey;
use App\Events\EntityChanged;
use App\Http\Resources\Extension\ExtensionListingResource;
use App\Models\Asset;
use App\Models\AssetListing;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceCategory;
use App\Models\User;
use App\Notifications\Push\ReviewSubmittedNotification;
use App\Services\Extension\ExtensionTokens;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Once;
use Laravel\Passport\Client;
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
        // The mechanism for several marketplaces, as once their adapters are there (config/extension.php).
        config(['extension.marketplaces' => ['shutterstock', '123rf', 'adobe_stock']]);
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

    public function test_counts_leave_out_marketplaces_the_extension_does_not_support(): void
    {
        $this->sent($this->account('shutterstock'));
        $this->sent($this->account('alamy'));

        $this->getJson('/api/extension/me')
            ->assertJsonPath('data.stats.sent', 1)
            ->assertJsonCount(1, 'data.accounts');
    }

    public function test_turned_off_accounts_are_left_alone(): void
    {
        $listing = $this->sent($this->account('shutterstock', ['status' => MarketplaceAccountStatus::Disabled]));

        $this->getJson('/api/extension/listings?marketplace=shutterstock')->assertJsonCount(0, 'data');
        $this->postJson('/api/extension/listings/report', ['listings' => [['id' => $listing->id, 'external_id' => 'X1']]])
            ->assertJsonPath('data.updated', 0);

        $this->assertNull($listing->fresh()->external_id);
    }

    public function test_report_never_erases_with_null(): void
    {
        $listing = $this->sent($this->account(), ['external_id' => 'U1']);

        $this->postJson('/api/extension/listings/report', ['listings' => [['id' => $listing->id, 'external_id' => null]]])
            ->assertUnprocessable();
        $this->postJson('/api/extension/listings/report', ['listings' => [['id' => $listing->id, 'moderation_status' => null]]])
            ->assertUnprocessable();

        $this->assertSame('U1', $listing->fresh()->external_id);
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

    public function test_report_tells_the_library_the_work_changed(): void
    {
        Event::fake([EntityChanged::class]);
        $listing = $this->sent($this->account());
        $untouched = $this->sent($this->account('123rf'));

        $this->postJson('/api/extension/listings/report', ['listings' => [
            ['id' => $listing->id, 'moderation_status' => 'pending'],
            ['id' => $untouched->id],
        ]])->assertOk();

        Event::assertDispatchedTimes(EntityChanged::class, 1);
        Event::assertDispatched(EntityChanged::class, fn ($event) => $event->id === $listing->asset->ulid && $event->reason === 'listing');
    }

    public function test_a_sent_work_awaits_review_until_the_extension_reports_it(): void
    {
        app(ExtensionTokens::class)->issue($this->user);
        $listing = $this->sent($this->account())->load('account.marketplace');

        $this->assertTrue($listing->awaitsReview());

        $listing->update(['moderation_status' => ModerationStatus::Pending]);
        $this->assertFalse($listing->awaitsReview());
    }

    public function test_only_where_the_extension_would_submit_it(): void
    {
        $listing = fn (MarketplaceAccount $account, array $attributes = []) => $this->sent($account, $attributes)->load('account.marketplace');

        // No extension connected yet.
        $this->assertFalse($listing($this->account())->awaitsReview());

        Once::flush();
        app(ExtensionTokens::class)->issue($this->user);

        $this->assertFalse($listing($this->account('shutterstock', ['label' => 'Off', 'settings' => ['auto_submit' => false]]))->awaitsReview());
        $this->assertFalse($listing($this->account('alamy'))->awaitsReview());
        $this->assertFalse($listing($this->account('shutterstock', ['label' => 'Old']), ['sent_at' => now()->subHours(25)])->awaitsReview());
        $this->assertFalse($listing($this->account('shutterstock', ['label' => 'Queued']), ['status' => ListingStatus::Queued])->awaitsReview());
    }

    /**
     * The user with the extension connected and notifications on in a browser.
     */
    protected function notifiable(): void
    {
        Notification::fake();
        app(ExtensionTokens::class)->issue($this->user);
        $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'key', 'token', 'aes128gcm');
    }

    protected function reportReview(AssetListing ...$listings): void
    {
        $this->postJson('/api/extension/listings/report', ['listings' => array_map(
            fn (AssetListing $listing) => ['id' => $listing->id, 'moderation_status' => 'pending'],
            $listings,
        )])->assertOk();
    }

    public function test_one_notification_once_every_work_on_its_way_is_in_review(): void
    {
        $this->notifiable();
        $account = $this->account();
        [$first, $second, $third] = [$this->sent($account), $this->sent($account), $this->sent($account)];

        $this->reportReview($first, $second);
        Notification::assertNothingSent();

        $this->reportReview($third);
        Notification::assertSentToTimes($this->user, ReviewSubmittedNotification::class, 1);
        Notification::assertSentTo($this->user, ReviewSubmittedNotification::class, fn ($notification) => $notification->count === 3);

        // A later status of the same works is not news.
        $this->postJson('/api/extension/listings/report', ['listings' => [['id' => $first->id, 'moderation_status' => 'accepted']]])->assertOk();
        Notification::assertSentToTimes($this->user, ReviewSubmittedNotification::class, 1);
    }

    public function test_works_still_queued_hold_the_notification_back(): void
    {
        $this->notifiable();
        $account = $this->account();
        $listing = $this->sent($account);
        $this->sent($account, ['status' => ListingStatus::Queued, 'sent_at' => null]);

        $this->reportReview($listing);

        Notification::assertNothingSent();
    }

    public function test_no_notification_when_the_event_is_off_or_no_browser_has_them_on(): void
    {
        $this->notifiable();
        $this->user->setSetting(UserSettingKey::NotifyReviewSubmitted, false);
        $this->reportReview($this->sent($this->account()));

        $this->user->setSetting(UserSettingKey::NotifyReviewSubmitted, true);
        $this->user->pushSubscriptions()->delete();
        $this->reportReview($this->sent($this->account('123rf')));

        Notification::assertNothingSent();
    }

    public function test_review_submitted_notification_reads_in_the_users_language(): void
    {
        $this->user->setSetting(UserSettingKey::GeneralLocale, 'ru');
        $notification = new ReviewSubmittedNotification(5);

        app()->setLocale($this->user->preferredLocale());
        $message = $notification->toWebPush($this->user, $notification)->toArray();

        $this->assertSame('Отправлено на проверку', $message['title']);
        $this->assertSame('5 работ на проверке на стоках', $message['body']);
        $this->assertSame(route('library'), $message['data']['url']);
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

    public function test_a_token_of_the_extension_client_without_the_scope_opens_no_api(): void
    {
        Passport::actingAs($this->user, [], 'api', Client::findOrFail(config('extension.client_id')));

        $this->getJson('/api/settings')->assertForbidden();
        $this->getJson('/api/extension/me')->assertForbidden();
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
