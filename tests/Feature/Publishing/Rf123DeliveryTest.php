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
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;
use App\Support\HostResolver;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class Rf123DeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected FakeFtpClient $ftp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        Storage::fake(AssetFileRole::Original->disk());
        Storage::fake(AssetFileRole::RasterCompanion->disk());
        config(['publishing.connect_backoff' => [0, 0]]);

        $this->ftp = new FakeFtpClient;
        $this->app->instance(FtpClient::class, $this->ftp);
        $this->mock(HostResolver::class)->shouldReceive('publicAddress')->andReturn('203.0.113.40');

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function account(): MarketplaceAccount
    {
        return $this->user->marketplaceAccounts()->create([
            'marketplace_id' => Marketplace::where('code', '123rf')->value('id'),
            'label' => '123RF',
            'host' => 'ftp.123rf.com',
            'login' => 'akadrov',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
        ]);
    }

    /** A small image: the work's width and height are what the checks read, not the file's. */
    protected function image(string $format = 'jpeg'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-');
        $image = imagecreatetruecolor(300, 200);
        $format === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 30);
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
            'description' => 'Icebergs floating in a glacier lagoon at sunrise, Iceland',
            'keywords' => ['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'ice', 'travel'],
            'original_filename' => 'lagoon.jpg',
            'checksum' => hash('sha256', "123rf-{$n}"),
            'width' => 3000,
            'height' => 2000,
            ...$attributes,
        ]);

        foreach ($files ?? [[AssetFileRole::Original, 'image/jpeg', $this->image()]] as [$role, $mime, $bytes]) {
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
        getimagesizefromstring($bytes, $info);

        return iptcparse($info['APP13']);
    }

    public function test_a_photo_goes_to_the_root_over_ftps_with_its_description_and_keywords(): void
    {
        $this->account();
        $work = $this->work();

        $this->send($work);

        $this->assertSame([['203.0.113.40', 21, true]], $this->ftp->connections);
        $this->assertSame([['akadrov', 'secret']], $this->ftp->logins);
        $this->assertSame(['lagoon.jpg'], array_keys($this->ftp->files));

        $iptc = $this->iptcOf($this->ftp->files['lagoon.jpg']);
        // 123RF has one text field: the description goes into the title slots too.
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise, Iceland', $iptc['2#120'][0]);
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise, Iceland', $iptc['2#005'][0]);
        $this->assertSame(['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'ice', 'travel'], $iptc['2#025']);

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame('lagoon.jpg', $listing->remote_filename);
        $this->assertSame(AssetStatus::Submitted, $work->fresh()->status);
    }

    public function test_a_png_illustration_goes_as_a_jpeg_copy(): void
    {
        $this->account();

        $this->send($this->work(['type' => 'illustration', 'original_filename' => 'pattern.png'], [[AssetFileRole::Original, 'image/png', $this->image('png')]]));

        $this->assertSame(['pattern.jpg'], array_keys($this->ftp->files));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->ftp->files['pattern.jpg'])[2]);
        $this->assertSame(['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'ice', 'travel'], $this->iptcOf($this->ftp->files['pattern.jpg'])['2#025']);
    }

    public function test_a_vector_goes_as_its_eps_and_jpeg_under_one_name(): void
    {
        $this->account();
        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n";

        $this->send($this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [
            [AssetFileRole::Original, 'application/postscript', $eps],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image()],
        ]));

        $this->assertSame(['logo.eps', 'logo.jpg'], array_keys($this->ftp->files));
        $this->assertSame($eps, $this->ftp->files['logo.eps']);
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise, Iceland', $this->iptcOf($this->ftp->files['logo.jpg'])['2#120'][0]);
        $this->assertSame('logo.eps', AssetListing::sole()->remote_filename);
    }

    public function test_works_123rf_never_takes_are_not_sent_and_say_why(): void
    {
        $account = $this->account();
        $svg = $this->work(['type' => 'vector', 'original_filename' => 'logo.svg'], [
            [AssetFileRole::Original, 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image()],
        ]);
        // 6 MP minimum: 2999 × 2000 is just under it.
        $small = $this->work(['width' => 2999, 'height' => 2000]);
        $video = $this->work(['type' => 'video', 'original_filename' => 'surf.mov'], [[AssetFileRole::Original, 'video/quicktime', 'moov']]);

        $items = collect($this->getJson('/api/listings/batch')->assertOk()->json('data.items'))->keyBy('asset');
        $errors = fn (Asset $asset) => array_column($items[$asset->ulid]['accounts'][0]['errors'] ?? [], 'key');

        $this->assertSame(['format'], $errors($svg));
        $this->assertSame(['resolution_min'], $errors($small));
        // Video goes to another 123RF server, not delivered yet.
        $this->assertSame(['unsupported_type'], $errors($video));

        $this->postJson('/api/listings/batch', ['pairs' => ["{$svg->ulid}|{$account->id}", "{$small->ulid}|{$account->id}", "{$video->ulid}|{$account->id}"]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', []);

        $this->assertSame(0, AssetListing::count());
        $this->assertSame([], $this->ftp->files);
    }
}
