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

class Pond5DeliveryTest extends TestCase
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
            'marketplace_id' => Marketplace::where('code', 'pond5')->value('id'),
            'label' => 'Pond5',
            'host' => 'ftp.pond5.com',
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
            'checksum' => hash('sha256', "pond5-{$n}"),
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

    public function test_a_photo_goes_to_the_root_over_ftps_with_its_metadata_inside(): void
    {
        $this->account();
        $work = $this->work();

        $this->send($work);

        $this->assertSame([['203.0.113.40', 21, true]], $this->ftp->connections);
        $this->assertSame([['akadrov', 'secret']], $this->ftp->logins);
        $this->assertSame(['lagoon.jpg'], array_keys($this->ftp->files));

        // Pond5 has a title and a description: each goes to its own field.
        $iptc = $this->iptcOf($this->ftp->files['lagoon.jpg']);
        $this->assertSame('Glacier lagoon', $iptc['2#005'][0]);
        $this->assertSame('Icebergs floating in a glacier lagoon at sunrise, Iceland', $iptc['2#120'][0]);
        $this->assertSame(['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'ice', 'travel'], $iptc['2#025']);

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame('lagoon.jpg', $listing->remote_filename);
    }

    public function test_a_png_photo_goes_as_a_jpeg_copy(): void
    {
        $this->account();

        $this->send($this->work(['original_filename' => 'pattern.png'], [[AssetFileRole::Original, 'image/png', $this->image('png')]]));

        $this->assertSame(['pattern.jpg'], array_keys($this->ftp->files));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->ftp->files['pattern.jpg'])[2]);
    }

    public function test_a_video_goes_as_it_is(): void
    {
        $this->account();

        $this->send($this->work(['type' => 'video', 'original_filename' => 'surf at dawn.mov', 'width' => 1920, 'height' => 1080], [[AssetFileRole::Original, 'video/quicktime', 'moov-bytes']]));

        $this->assertSame(['surf_at_dawn.mov' => 'moov-bytes'], $this->ftp->files);
        $this->assertSame('surf_at_dawn.mov', AssetListing::sole()->remote_filename);
    }

    public function test_names_keep_letters_digits_and_underscores_only(): void
    {
        $this->account();
        $first = $this->work(['original_filename' => 'Sunset over-lake (2).jpg']);
        // Another work whose name comes out the same gets the suffix of its id, with an underscore.
        $second = $this->work(['original_filename' => 'Sunset_over_lake_2.jpg']);

        $this->send($first);
        $this->send($second);

        $suffix = strtolower(substr($second->ulid, -4));
        $this->assertSame(['Sunset_over_lake_2.jpg', "Sunset_over_lake_2_{$suffix}.jpg"], array_keys($this->ftp->files));
    }

    public function test_works_pond5_never_takes_are_not_sent_and_say_why(): void
    {
        $account = $this->account();
        // 2 MP minimum: 1999 × 1000 is just under it.
        $small = $this->work(['width' => 1999, 'height' => 1000]);
        $vector = $this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [[AssetFileRole::Original, 'application/postscript', '%!PS']]);

        $items = collect($this->getJson('/api/listings/batch')->assertOk()->json('data.items'))->keyBy('asset');
        $errors = fn (Asset $asset) => array_column($items[$asset->ulid]['accounts'][0]['errors'] ?? [], 'key');

        $this->assertSame(['resolution_min'], $errors($small));
        $this->assertSame(['unsupported_type'], $errors($vector));

        $this->postJson('/api/listings/batch', ['pairs' => ["{$small->ulid}|{$account->id}", "{$vector->ulid}|{$account->id}"]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', []);

        $this->assertSame(0, AssetListing::count());
        $this->assertSame([], $this->ftp->files);
    }
}
