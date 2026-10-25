<?php

namespace Tests\Feature\Publishing;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Jobs\PublishListings;
use App\Models\Asset;
use App\Models\AssetListing;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplaces\Delivery\Delivery;
use App\Services\Marketplaces\Delivery\DeliveryFailed;
use App\Services\Marketplaces\Delivery\DeliverySession;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        FakeDelivery::reset();
        config([
            'publishing.deliveries' => ['shutterstock' => FakeDelivery::class, 'alamy' => FakeDelivery::class],
            'publishing.connect_backoff' => [0, 0],
        ]);
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
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

    /**
     * A ready photo that meets the Shutterstock rules, with its original file row.
     */
    protected function work(array $attributes = [], string $mime = 'image/jpeg', ?User $user = null): Asset
    {
        static $n = 0;
        $n++;

        $asset = ($user ?? $this->user)->assets()->create([
            'type' => 'photo',
            'status' => AssetStatus::Ready,
            'title' => 'Glacier lagoon',
            'description' => 'Icebergs floating in a glacier lagoon at sunrise',
            'keywords' => ['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'nature', 'ice'],
            'categories' => ['shutterstock' => ['Nature']],
            'original_filename' => "IMG_{$n}.jpg",
            'checksum' => hash('sha256', "work-{$n}"),
            'width' => 3000,
            'height' => 2000,
            ...$attributes,
        ]);

        $asset->files()->create([
            'role' => AssetFileRole::Original,
            'disk' => AssetFileRole::Original->disk(),
            'path' => "assets/{$asset->ulid}/original",
            'mime' => $mime,
            'size_bytes' => 1_000_000,
            'checksum' => $asset->checksum,
        ]);

        return $asset;
    }

    protected function listing(Asset $asset, MarketplaceAccount $account, array $attributes = []): AssetListing
    {
        return AssetListing::create([
            'asset_id' => $asset->id,
            'marketplace_account_id' => $account->id,
            'status' => ListingStatus::Queued,
            ...$attributes,
        ]);
    }

    protected function accountSummary(array $summary, MarketplaceAccount $account): array
    {
        return collect($summary['accounts'])->firstWhere('account', $account->id);
    }

    public function test_batch_summary_counts_ready_works_per_account_with_their_warnings(): void
    {
        $shutterstock = $this->account();
        $alamy = $this->account('alamy');
        $off = $this->account('shutterstock', ['label' => 'Second', 'status' => MarketplaceAccountStatus::Disabled]);

        $good = $this->work();
        $weak = $this->work([
            'description' => '',
            'keywords' => ['glacier', 'lagoon'],
            'is_ai_generated' => true,
            'width' => 1000,
            'height' => 1000,
        ], 'image/png');
        $this->work(['type' => 'video', 'width' => 1920, 'height' => 1080], 'video/mp4');
        $this->work(['status' => AssetStatus::Draft]);

        $summary = $this->getJson('/api/listings/batch')->assertOk()->json('data');

        $this->assertSame(3, $summary['works']);

        $shutter = $this->accountSummary($summary, $shutterstock);
        $this->assertNull($shutter['blocked']);
        $this->assertSame(3, $shutter['works']);
        $this->assertSame(0, $shutter['unsupported']);
        $this->assertSame([$weak->ulid], array_column($shutter['warnings'], 'asset'));
        $this->assertSame(
            ['format', 'resolution_min', 'ai_not_accepted', 'description_required', 'keywords_min'],
            array_column($shutter['warnings'][0]['warnings'], 'key'),
        );
        $this->assertSame(['format' => 'PNG', 'formats' => 'JPEG, TIFF'], $shutter['warnings'][0]['warnings'][0]['params']);
        $this->assertSame(['min' => 7, 'count' => 2], $shutter['warnings'][0]['warnings'][4]['params']);

        // Alamy takes no video and needs a title, not a description.
        $alamySummary = $this->accountSummary($summary, $alamy);
        $this->assertSame(2, $alamySummary['works']);
        $this->assertSame(1, $alamySummary['unsupported']);
        $this->assertNotContains('description_required', array_column($alamySummary['warnings'][0]['warnings'] ?? [], 'key'));

        $this->assertSame('disabled', $this->accountSummary($summary, $off)['blocked']);
        $this->assertSame(0, $this->accountSummary($summary, $off)['works']);
        $this->assertNotNull($good);
    }

    public function test_publishing_sends_ready_works_in_one_session_and_marks_them_submitted(): void
    {
        $account = $this->account();
        $first = $this->work();
        $second = $this->work(['keywords' => ['glacier']]);

        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])
            ->assertAccepted()
            ->assertJsonPath("data.queued.{$account->id}", 2)
            ->assertJsonPath('data.summary.works', 0);

        $this->assertSame(1, FakeDelivery::$opens);
        $this->assertSame([$first->ulid, $second->ulid], array_column(FakeDelivery::$sent, 0));

        $listing = AssetListing::where('asset_id', $first->id)->sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame($first->original_filename, $listing->remote_filename);
        $this->assertNotNull($listing->sent_at);
        $this->assertSame(1, $listing->attempts);
        // jsonb keeps its own key order.
        $this->assertEquals(AssetListing::snapshot($first, $account->marketplace), $listing->metadata);
        $this->assertSame([], $listing->warnings);
        // The metadata went out as it was when the upload started.
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise', FakeDelivery::$sent[0][1]['description']);

        // Sent with a warning: it does not stop the upload.
        $this->assertSame('keywords_min', AssetListing::where('asset_id', $second->id)->sole()->warnings[0]['key']);

        $this->assertSame(AssetStatus::Submitted, $first->refresh()->status);
        $this->assertSame(AssetStatus::Submitted, $second->refresh()->status);
    }

    public function test_a_new_batch_does_not_send_a_work_again(): void
    {
        $account = $this->account();
        $asset = $this->work();
        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        // Back to ready by hand, e.g. to send it to a newly connected marketplace.
        $asset->update(['status' => AssetStatus::Ready]);
        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', []);

        $this->assertSame(1, AssetListing::count());
        $this->assertCount(1, FakeDelivery::$sent);
    }

    public function test_a_work_sent_to_one_account_and_failed_on_another_stays_ready(): void
    {
        $shutterstock = $this->account();
        $alamy = $this->account('alamy');
        $asset = $this->work();
        FakeDelivery::$sendFails[$asset->ulid] = ['other'];

        // Shutterstock first: its upload fails, Alamy's gets through.
        $this->postJson('/api/listings/batch', ['accounts' => [$shutterstock->id, $alamy->id]])->assertAccepted();

        $this->assertSame(ListingStatus::Failed, $asset->listings()->where('marketplace_account_id', $shutterstock->id)->sole()->status);
        $this->assertSame('other', $asset->listings()->where('marketplace_account_id', $shutterstock->id)->sole()->error);
        $this->assertSame(ListingStatus::Sent, $asset->listings()->where('marketplace_account_id', $alamy->id)->sole()->status);
        $this->assertSame(AssetStatus::Ready, $asset->refresh()->status);

        // The next batch takes only the failed pair.
        $this->postJson('/api/listings/batch', ['accounts' => [$shutterstock->id, $alamy->id]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', [$shutterstock->id => 1]);

        $this->assertSame(ListingStatus::Sent, $asset->listings()->where('marketplace_account_id', $shutterstock->id)->sole()->status);
        $this->assertSame(AssetStatus::Submitted, $asset->refresh()->status);
        $this->assertCount(2, FakeDelivery::$sent);
    }

    public function test_one_failed_upload_does_not_stop_the_session(): void
    {
        $account = $this->account();
        $broken = $this->work();
        $fine = $this->work();
        FakeDelivery::$sendFails[$broken->ulid] = [AssetListing::ERROR_FILE];

        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        $this->assertSame(AssetListing::ERROR_FILE, $broken->listings()->sole()->error);
        $this->assertSame(ListingStatus::Sent, $fine->listings()->sole()->status);
        $this->assertSame(1, FakeDelivery::$opens);
    }

    public function test_a_rejected_sign_in_fails_the_listings_and_marks_the_account_without_retrying(): void
    {
        $account = $this->account();
        $asset = $this->work();
        FakeDelivery::$openFails = ['auth'];

        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        $this->assertSame(1, FakeDelivery::$opens);
        $this->assertSame(ListingStatus::Failed, $asset->listings()->sole()->status);
        $this->assertSame('auth', $asset->listings()->sole()->error);
        $account->refresh();
        $this->assertSame(MarketplaceAccountStatus::InvalidCredentials, $account->status);
        $this->assertSame('auth', $account->last_error);
        $this->assertSame(AssetStatus::Ready, $asset->refresh()->status);

        // The account is blocked now: the batch skips it, a retry is refused.
        $this->assertSame('invalid_credentials', $this->accountSummary($this->getJson('/api/listings/batch')->json('data'), $account)['blocked']);
        $this->postJson('/api/listings/'.$asset->listings()->sole()->id.'/retry')
            ->assertConflict()
            ->assertJsonPath('message', __('library.checks.invalid_credentials'));
    }

    public function test_an_unreachable_server_is_tried_again_and_then_fails_with_connect(): void
    {
        $account = $this->account();
        $asset = $this->work();
        FakeDelivery::$openFails = ['connect', 'connect', 'connect'];

        // The synchronous test queue runs the delayed retries right away.
        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        $this->assertSame(3, FakeDelivery::$opens);
        $this->assertSame('connect', $asset->listings()->sole()->error);
        $this->assertSame(ListingStatus::Failed, $asset->listings()->sole()->status);
        // An unreachable server says nothing about the password.
        $this->assertSame(MarketplaceAccountStatus::Active, $account->refresh()->status);
    }

    public function test_a_lost_connection_sends_the_file_again_in_a_new_session(): void
    {
        $account = $this->account();
        $asset = $this->work();
        FakeDelivery::$sendFails[$asset->ulid] = ['connect'];

        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        $listing = $asset->listings()->sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame(2, $listing->attempts);
        $this->assertSame(2, FakeDelivery::$opens);
        $this->assertCount(1, FakeDelivery::$sent);
    }

    public function test_a_marketplace_without_delivery_fails_with_unsupported(): void
    {
        config(['publishing.deliveries' => []]);
        $account = $this->account();
        $asset = $this->work();

        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        $this->assertSame(AssetListing::ERROR_UNSUPPORTED, $asset->listings()->sole()->error);
    }

    public function test_an_account_turned_off_after_queuing_fails_its_listings(): void
    {
        Queue::fake();
        $account = $this->account();
        $asset = $this->work();
        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();
        Queue::assertPushed(PublishListings::class, fn (PublishListings $job) => $job->account->is($account) && $job->queue === 'publishing');

        $account->update(['status' => MarketplaceAccountStatus::Disabled]);
        // Run by hand: dispatchSync() of a queued job goes through the faked queue.
        app()->call([new PublishListings($account), 'handle']);

        $this->assertSame(AssetListing::ERROR_ACCOUNT, $asset->listings()->sole()->error);
        $this->assertSame(0, FakeDelivery::$opens);
    }

    public function test_a_work_moved_out_of_ready_before_its_turn_is_cancelled(): void
    {
        $account = $this->account();
        $draft = $this->work();
        $ready = $this->work();
        $this->listing($draft, $account);
        $this->listing($ready, $account);
        $draft->update(['status' => AssetStatus::Draft]);

        PublishListings::dispatchSync($account);

        $this->assertSame(ListingStatus::Cancelled, $draft->listings()->sole()->status);
        $this->assertSame(ListingStatus::Sent, $ready->listings()->sole()->status);
        $this->assertSame(AssetStatus::Draft, $draft->refresh()->status);
    }

    public function test_an_upload_cut_off_by_a_killed_worker_is_sent_again(): void
    {
        $account = $this->account();
        $asset = $this->work();
        $this->listing($asset, $account, ['status' => ListingStatus::Sending, 'attempts' => 1]);

        PublishListings::dispatchSync($account);

        $this->assertSame(ListingStatus::Sent, $asset->listings()->sole()->status);
        $this->assertSame(2, $asset->listings()->sole()->attempts);
    }

    public function test_sign_ins_are_limited_per_account(): void
    {
        $account = $this->account();
        $asset = $this->work();
        $this->listing($asset, $account);

        for ($i = 0; $i < config('publishing.sign_ins'); $i++) {
            RateLimiter::hit("marketplace-publish-sign-in:{$account->id}", 600);
        }

        PublishListings::dispatchSync($account);

        $this->assertSame(0, FakeDelivery::$opens);
        $this->assertSame(ListingStatus::Queued, $asset->listings()->sole()->status);
    }

    public function test_a_queued_listing_can_be_cancelled_and_sent_again(): void
    {
        Queue::fake();
        $account = $this->account();
        $asset = $this->work();
        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();
        $listing = $asset->listings()->sole();

        $this->postJson("/api/listings/{$listing->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/listings/{$listing->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', __('library.publishing.cancel_too_late'));

        $this->postJson("/api/listings/{$listing->id}/retry")->assertOk()->assertJsonPath('data.status', 'queued');
        $this->postJson("/api/listings/{$listing->id}/retry")
            ->assertConflict()
            ->assertJsonPath('message', __('library.publishing.retry_not_failed'));

        Queue::assertPushed(PublishListings::class, 2);
    }

    public function test_an_upload_on_its_way_cannot_be_cancelled(): void
    {
        $account = $this->account();
        $listing = $this->listing($this->work(), $account, ['status' => ListingStatus::Sending]);

        $this->postJson("/api/listings/{$listing->id}/cancel")->assertConflict();

        $this->assertSame(ListingStatus::Sending, $listing->refresh()->status);
    }

    public function test_listings_and_accounts_of_another_user_are_out_of_reach(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $theirs = $this->account(user: $other);
        $listing = $this->listing($this->work(user: $other), $theirs, ['status' => ListingStatus::Failed]);
        $this->work();

        $this->postJson("/api/listings/{$listing->id}/cancel")->assertNotFound();
        $this->postJson("/api/listings/{$listing->id}/retry")->assertNotFound();
        $this->postJson('/api/listings/batch', ['accounts' => [$theirs->id]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', []);

        $this->assertSame(1, AssetListing::count());
        $this->assertSame([], $this->getJson('/api/listings/batch')->json('data.accounts'));
        Queue::assertNothingPushed();
    }

    public function test_the_batch_needs_accounts(): void
    {
        $this->postJson('/api/listings/batch', [])->assertUnprocessable()->assertJsonValidationErrors('accounts');
    }

    public function test_a_work_edited_after_sending_is_shown_as_outdated(): void
    {
        $account = $this->account();
        $asset = $this->work();
        $this->postJson('/api/listings/batch', ['accounts' => [$account->id]])->assertAccepted();

        $this->getJson("/api/assets?ids[]={$asset->ulid}")
            ->assertJsonPath('data.0.status', 'submitted')
            ->assertJsonPath('data.0.listings.0.account_id', $account->id)
            ->assertJsonPath('data.0.listings.0.status', 'sent')
            ->assertJsonPath('data.0.listings.0.outdated', false);

        $this->patchJson("/api/assets/{$asset->ulid}", ['keywords' => [...$asset->keywords, 'travel']])
            ->assertOk()
            ->assertJsonPath('data.listings.0.outdated', true);
    }

    public function test_the_user_cannot_mark_a_work_submitted(): void
    {
        $asset = $this->work();

        $this->patchJson("/api/assets/{$asset->ulid}", ['status' => 'submitted'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_a_work_whose_file_is_being_uploaded_cannot_be_deleted(): void
    {
        $account = $this->account();
        $asset = $this->work(['status' => AssetStatus::Archived]);
        $listing = $this->listing($asset, $account, ['status' => ListingStatus::Sending]);

        $this->deleteJson("/api/assets/{$asset->ulid}")
            ->assertConflict()
            ->assertJsonPath('message', __('library.delete.publishing'));

        // Once sent, the work can go: the marketplace keeps its copy.
        $listing->update(['status' => ListingStatus::Sent]);
        $this->deleteJson("/api/assets/{$asset->ulid}")->assertNoContent();
        $this->assertSame(0, AssetListing::count());
    }

    public function test_an_archived_work_with_a_queued_listing_can_be_deleted(): void
    {
        $asset = $this->work(['status' => AssetStatus::Archived]);
        $this->listing($asset, $this->account());

        $this->deleteJson("/api/assets/{$asset->ulid}")->assertNoContent();

        $this->assertSame(0, AssetListing::count());
    }

    public function test_a_cancelled_listing_keeps_the_work_ready_for_the_next_batch(): void
    {
        Queue::fake();
        $shutterstock = $this->account();
        $alamy = $this->account('alamy');
        $asset = $this->work();
        $this->listing($asset, $alamy, ['status' => ListingStatus::Sent]);
        $listing = $this->listing($asset, $shutterstock);

        $this->postJson("/api/listings/{$listing->id}/cancel")->assertOk();

        // Sent to Alamy only: not submitted, Shutterstock still has to get it.
        $this->assertSame(AssetStatus::Ready, $asset->refresh()->status);
        $this->postJson('/api/listings/batch', ['accounts' => [$shutterstock->id, $alamy->id]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', [$shutterstock->id => 1]);
    }

    public function test_a_listing_of_a_work_out_of_the_batch_is_not_retried(): void
    {
        Queue::fake();
        $asset = $this->work(['status' => AssetStatus::Archived]);
        $listing = $this->listing($asset, $this->account(), ['status' => ListingStatus::Failed, 'error' => 'other']);

        $this->postJson("/api/listings/{$listing->id}/retry")
            ->assertConflict()
            ->assertJsonPath('message', __('library.publishing.retry_not_ready'));

        $this->assertSame(ListingStatus::Failed, $listing->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_session_that_gave_up_fails_its_listings(): void
    {
        $account = $this->account();
        $queued = $this->listing($this->work(), $account);
        $sending = $this->listing($this->work(), $account, ['status' => ListingStatus::Sending]);
        $sent = $this->listing($this->work(), $account, ['status' => ListingStatus::Sent]);

        (new PublishListings($account))->failed(new \RuntimeException('Gave up'));

        $this->assertSame('other', $queued->refresh()->error);
        $this->assertSame(ListingStatus::Failed, $queued->status);
        $this->assertSame(ListingStatus::Failed, $sending->refresh()->status);
        $this->assertSame(ListingStatus::Sent, $sent->refresh()->status);
    }
}

/**
 * Stands in for an FTP delivery: records what is sent, fails as told.
 */
class FakeDelivery implements Delivery
{
    public static int $opens = 0;

    /** @var list<array{0: string, 1: array}> asset ulid and the metadata sent */
    public static array $sent = [];

    /** @var list<string> reasons the next sign-ins fail with */
    public static array $openFails = [];

    /** @var array<string, list<string>> reasons the next uploads of a work fail with, by asset ulid */
    public static array $sendFails = [];

    public static function reset(): void
    {
        self::$opens = 0;
        self::$sent = [];
        self::$openFails = [];
        self::$sendFails = [];
    }

    public function open(MarketplaceAccount $account): DeliverySession
    {
        self::$opens++;

        if ($reason = array_shift(self::$openFails)) {
            throw new DeliveryFailed($reason);
        }

        return new class implements DeliverySession
        {
            public function send(AssetListing $listing): string
            {
                $ulid = $listing->asset->ulid;
                FakeDelivery::$sendFails[$ulid] ??= [];

                if ($reason = array_shift(FakeDelivery::$sendFails[$ulid])) {
                    throw new DeliveryFailed($reason);
                }

                FakeDelivery::$sent[] = [$ulid, $listing->metadata];

                return $listing->asset->original_filename;
            }

            public function close(): void {}
        };
    }
}
