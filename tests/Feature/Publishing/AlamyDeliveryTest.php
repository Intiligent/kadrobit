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

class AlamyDeliveryTest extends TestCase
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
        $this->mock(HostResolver::class)->shouldReceive('publicAddress')->andReturn('203.0.113.30');

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function account(): MarketplaceAccount
    {
        return $this->user->marketplaceAccounts()->create([
            'marketplace_id' => Marketplace::where('code', 'alamy')->value('id'),
            'label' => 'Alamy',
            'host' => 'upload.alamy.com',
            'login' => 'author@example.com',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
        ]);
    }

    /** A small image: the work's width and height are what the checks read, not the file's. */
    protected function image(string $format = 'jpeg', bool $progressive = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-');
        $image = imagecreatetruecolor(300, 200);
        imageinterlace($image, $progressive);
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
            'title' => 'Glacier lagoon at sunrise',
            'description' => 'Icebergs floating in a glacier lagoon, Iceland',
            'keywords' => ['glacier', 'lagoon', 'iceberg', 'iceland'],
            'original_filename' => 'lagoon.jpg',
            'checksum' => hash('sha256', "alamy-{$n}"),
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

    /** A progressive frame (SOF2): the marker never appears inside the image data, where 0xFF is followed by 0x00. */
    protected function isProgressive(string $bytes): bool
    {
        return str_contains($bytes, "\xFF\xC2");
    }

    public function test_a_photo_goes_to_the_stock_folder_over_plain_ftp_with_the_title_as_its_caption(): void
    {
        $this->account();
        $original = $this->image();
        $work = $this->work([], [[AssetFileRole::Original, 'image/jpeg', $original]]);

        $this->send($work);

        $this->assertSame([['203.0.113.30', 21, false]], $this->ftp->connections);
        $this->assertSame([['author@example.com', 'secret']], $this->ftp->logins);
        $this->assertSame(['Stock/lagoon.jpg'], array_keys($this->ftp->files));

        $iptc = $this->iptcOf($this->ftp->files['Stock/lagoon.jpg']);
        // Alamy's Caption comes from the IPTC description: the work's title goes there.
        $this->assertSame('Glacier lagoon at sunrise', $iptc['2#120'][0]);
        $this->assertSame('Glacier lagoon at sunrise', $iptc['2#005'][0]);
        $this->assertSame(['glacier', 'lagoon', 'iceberg', 'iceland'], $iptc['2#025']);

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame('lagoon.jpg', $listing->remote_filename);
        $this->assertSame(AssetStatus::Submitted, $work->fresh()->status);
    }

    public function test_a_png_and_a_progressive_jpeg_go_as_a_baseline_jpeg_copy(): void
    {
        $this->account();
        $progressive = $this->image(progressive: true);
        $this->assertTrue($this->isProgressive($progressive));

        $this->send(
            $this->work(['original_filename' => 'lagoon.png'], [[AssetFileRole::Original, 'image/png', $this->image('png')]]),
            $this->work(['original_filename' => 'pier.jpg'], [[AssetFileRole::Original, 'image/jpeg', $progressive]]),
        );

        $this->assertSame(['Stock/lagoon.jpg', 'Stock/pier.jpg'], array_keys($this->ftp->files));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->ftp->files['Stock/lagoon.jpg'])[2]);
        $this->assertFalse($this->isProgressive($this->ftp->files['Stock/pier.jpg']));
        $this->assertSame('Glacier lagoon at sunrise', $this->iptcOf($this->ftp->files['Stock/pier.jpg'])['2#120'][0]);
    }

    public function test_a_vector_goes_as_its_eps_and_jpeg_under_one_name_in_the_vectors_folder(): void
    {
        $this->account();
        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n";

        $this->send($this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [
            [AssetFileRole::Original, 'application/postscript', $eps],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image()],
        ]));

        $this->assertSame(['Vectors/logo.eps', 'Vectors/logo.jpg'], array_keys($this->ftp->files));
        $this->assertSame($eps, $this->ftp->files['Vectors/logo.eps']);
        $this->assertSame('Glacier lagoon at sunrise', $this->iptcOf($this->ftp->files['Vectors/logo.jpg'])['2#120'][0]);
        $this->assertSame('logo.eps', AssetListing::sole()->remote_filename);
    }

    public function test_works_alamy_never_takes_are_not_sent_and_say_why(): void
    {
        $account = $this->account();
        $svg = $this->work(['type' => 'vector', 'original_filename' => 'logo.svg'], [
            [AssetFileRole::Original, 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image()],
        ]);
        // 17 MB uncompressed is about 5.94 MP: 2900 × 2000 is just under it.
        $small = $this->work(['width' => 2900, 'height' => 2000]);
        $video = $this->work(['type' => 'video', 'original_filename' => 'surf.mov'], [[AssetFileRole::Original, 'video/quicktime', 'moov']]);

        $items = collect($this->getJson('/api/listings/batch')->assertOk()->json('data.items'))->keyBy('asset');
        $errors = fn (Asset $asset) => array_column($items[$asset->ulid]['accounts'][0]['errors'] ?? [], 'key');

        $this->assertSame(['format'], $errors($svg));
        $this->assertSame(['format' => 'SVG', 'formats' => 'EPS'], $items[$svg->ulid]['accounts'][0]['errors'][0]['params']);
        $this->assertSame(['resolution_min'], $errors($small));
        // Alamy takes no video from contributors.
        $this->assertSame(['unsupported_type'], $errors($video));

        $this->postJson('/api/listings/batch', ['pairs' => ["{$svg->ulid}|{$account->id}", "{$small->ulid}|{$account->id}", "{$video->ulid}|{$account->id}"]])
            ->assertAccepted()
            ->assertJsonPath('data.queued', []);

        $this->assertSame(0, AssetListing::count());
        $this->assertSame([], $this->ftp->files);
    }
}
