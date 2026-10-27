<?php

namespace Tests\Feature\Publishing;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Models\Asset;
use App\Models\AssetListing;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;
use App\Support\HostResolver;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ShutterstockDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected FakeFtpClient $ftp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        Storage::fake(AssetFileRole::Original->disk());
        config(['publishing.connect_backoff' => [0, 0]]);

        $this->ftp = new FakeFtpClient;
        $this->app->instance(FtpClient::class, $this->ftp);
        $this->mock(HostResolver::class)->shouldReceive('publicAddress')->andReturn('203.0.113.10');

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function account(array $attributes = []): MarketplaceAccount
    {
        return $this->user->marketplaceAccounts()->create([
            'marketplace_id' => Marketplace::where('code', 'shutterstock')->value('id'),
            'label' => 'Shutterstock',
            'host' => 'ftps.shutterstock.com',
            'login' => 'akadrov',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
            ...$attributes,
        ]);
    }

    protected function jpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-');
        imagejpeg(imagecreatetruecolor(2600, 1800), $path, 30);
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A ready work with its files in storage: [role, mime, bytes].
     */
    protected function work(array $attributes = [], ?array $files = null): Asset
    {
        static $n = 0;
        $n++;

        $asset = $this->user->assets()->create([
            'type' => 'photo',
            'status' => AssetStatus::Ready,
            'title' => 'Glacier lagoon',
            'description' => 'Icebergs floating in a glacier lagoon at sunrise',
            'keywords' => ['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'nature', 'ice'],
            'categories' => ['shutterstock' => ['Nature']],
            'original_filename' => 'lagoon.jpg',
            'checksum' => hash('sha256', "delivery-{$n}"),
            'width' => 2600,
            'height' => 1800,
            ...$attributes,
        ]);

        foreach ($files ?? [[AssetFileRole::Original, 'image/jpeg', $this->jpeg()]] as [$role, $mime, $bytes]) {
            $path = "assets/{$asset->ulid}/{$role->value}";
            Storage::disk($role->disk())->put($path, $bytes);
            $asset->files()->create(['role' => $role, 'disk' => $role->disk(), 'path' => $path, 'mime' => $mime, 'size_bytes' => strlen($bytes)]);
        }

        return $asset;
    }

    protected function send(Asset ...$assets): void
    {
        $account = MarketplaceAccount::sole();

        $this->postJson('/api/listings/batch', ['pairs' => array_map(fn (Asset $asset) => "{$asset->ulid}|{$account->id}", $assets)])->assertAccepted();
    }

    /** @return array<string, list<string>> */
    protected function iptcOf(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-');
        file_put_contents($path, $bytes);
        getimagesize($path, $info);
        unlink($path);

        return iptcparse($info['APP13']);
    }

    public function test_a_jpeg_goes_over_ftps_with_its_metadata_inside(): void
    {
        $this->account();
        $work = $this->work();

        $this->send($work);

        $this->assertSame([['203.0.113.10', 21, true]], $this->ftp->connections);
        $this->assertSame([['akadrov', 'secret']], $this->ftp->logins);
        $this->assertSame(['lagoon.jpg'], array_keys($this->ftp->files));

        $iptc = $this->iptcOf($this->ftp->files['lagoon.jpg']);
        // One text field on Shutterstock: the description goes into the title fields too.
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise', $iptc['2#120'][0]);
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise', $iptc['2#005'][0]);
        $this->assertSame(['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'nature', 'ice'], $iptc['2#025']);
        $this->assertStringContainsString('<dc:description>', $this->ftp->files['lagoon.jpg']);

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame('lagoon.jpg', $listing->remote_filename);
        $this->assertSame(AssetStatus::Submitted, $work->fresh()->status);
        // The original in storage is left as it was.
        getimagesize(Storage::disk('r2-private')->path("assets/{$work->ulid}/original"), $info);
        $this->assertArrayNotHasKey('APP13', $info);
        $this->assertSame([], glob(storage_path('app/private/publishing/*')));
    }

    public function test_another_work_with_the_same_name_gets_a_suffix_and_the_same_work_keeps_its_name(): void
    {
        $account = $this->account();
        $first = $this->work();
        $second = $this->work();

        $this->send($first, $second);

        $suffix = strtolower(substr($second->ulid, -4));
        $this->assertSame(['lagoon.jpg', "lagoon-{$suffix}.jpg"], array_keys($this->ftp->files));

        // Sent again (failed, then retried): the same name, so the server's file is replaced, not copied.
        AssetListing::where('asset_id', $second->id)->update(['status' => ListingStatus::Failed]);
        $second->refresh()->update(['status' => AssetStatus::Ready]);
        $this->ftp->files = [];
        $this->postJson('/api/listings/'.AssetListing::where('asset_id', $second->id)->value('id').'/retry')->assertOk();

        $this->assertSame(["lagoon-{$suffix}.jpg"], array_keys($this->ftp->files));
        $this->assertSame($account->id, AssetListing::where('asset_id', $second->id)->value('marketplace_account_id'));
    }

    public function test_a_vector_goes_with_its_jpeg_under_the_same_name(): void
    {
        $this->account();
        $this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [
            [AssetFileRole::Original, 'application/postscript', "%!PS-Adobe-3.0 EPSF-3.0\n%%EOF\n"],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->jpeg()],
        ]);

        $this->send(Asset::sole());

        $this->assertSame(['logo.eps', 'logo.jpg'], array_keys($this->ftp->files));
        $this->assertSame("%!PS-Adobe-3.0 EPSF-3.0\n%%EOF\n", $this->ftp->files['logo.eps']);
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise', $this->iptcOf($this->ftp->files['logo.jpg'])['2#120'][0]);
        $this->assertSame('logo.eps', AssetListing::sole()->remote_filename);
    }

    public function test_a_short_upload_is_sent_again(): void
    {
        $this->account();
        $this->ftp->shortUploads = 1;

        $this->send($this->work());

        $this->assertSame(2, $this->ftp->puts);
        $this->assertSame(ListingStatus::Sent, AssetListing::sole()->status);
    }

    public function test_a_connection_that_keeps_dropping_fails_the_listing_after_its_tries(): void
    {
        $this->account();
        $this->ftp->shortUploads = PHP_INT_MAX;

        $this->send($this->work());

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Failed, $listing->status);
        $this->assertSame(ConnectionResult::CONNECT, $listing->error);
        $this->assertSame(config('publishing.connect_tries'), count($this->ftp->connections));
    }

    public function test_a_rejected_login_fails_the_listings_and_turns_the_account_off(): void
    {
        $account = $this->account();
        $this->ftp->loginFailure = ConnectionResult::AUTH;

        $this->send($this->work());

        $this->assertSame(ConnectionResult::AUTH, AssetListing::sole()->error);
        $this->assertSame(MarketplaceAccountStatus::InvalidCredentials, $account->fresh()->status);
        $this->assertSame([], $this->ftp->files);
        $this->assertSame(1, $this->ftp->closed);
    }

    public function test_a_work_whose_file_is_missing_fails_alone(): void
    {
        $this->account();
        $missing = $this->work();
        Storage::disk('r2-private')->delete("assets/{$missing->ulid}/original");
        $present = $this->work(['original_filename' => 'other.jpg']);

        $this->send($missing, $present);

        $this->assertSame(AssetListing::ERROR_FILE, AssetListing::where('asset_id', $missing->id)->value('error'));
        $this->assertSame(ListingStatus::Sent, AssetListing::where('asset_id', $present->id)->value('status'));
        $this->assertSame(['other.jpg'], array_keys($this->ftp->files));
    }

    public function test_a_host_that_does_not_resolve_to_a_public_address_is_not_contacted(): void
    {
        $this->mock(HostResolver::class)->shouldReceive('publicAddress')->andReturn(null);
        $this->account();

        $this->send($this->work());

        $this->assertSame(ConnectionResult::CONNECT, AssetListing::sole()->error);
        $this->assertSame([], $this->ftp->connections);
    }
}
