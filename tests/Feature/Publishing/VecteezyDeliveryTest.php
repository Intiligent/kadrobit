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
use App\Services\Marketplaces\Delivery\Ftp\SftpClient;
use App\Support\HostResolver;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class VecteezyDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected FakeSftpClient $sftp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OauthSeeder::class, MarketplaceSeeder::class]);
        Storage::fake(AssetFileRole::Original->disk());
        Storage::fake(AssetFileRole::RasterCompanion->disk());
        config(['publishing.connect_backoff' => [0, 0]]);

        $this->sftp = new FakeSftpClient;
        $this->app->instance(SftpClient::class, $this->sftp);
        // Nothing may go over FTP.
        $this->app->instance(FtpClient::class, new FakeFtpClient);
        $this->mock(HostResolver::class)->shouldReceive('publicAddress')->andReturn('203.0.113.50');

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function account(): MarketplaceAccount
    {
        return $this->user->marketplaceAccounts()->create([
            'marketplace_id' => Marketplace::where('code', 'vecteezy')->value('id'),
            'label' => 'Vecteezy',
            'host' => 'sftp.contributors.example',
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
            'title' => 'Icebergs in a glacier lagoon',
            'description' => 'Icebergs floating in a glacier lagoon at sunrise, Iceland',
            'keywords' => ['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise'],
            'original_filename' => 'lagoon.jpg',
            'checksum' => hash('sha256', "vecteezy-{$n}"),
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

    /** @return list<list<string>> */
    protected function csv(string $remote): array
    {
        return array_map(fn (string $line) => str_getcsv($line, escape: ''), explode("\n", trim($this->sftp->files[$remote])));
    }

    /** @return array<string, list<string>> */
    protected function iptcOf(string $bytes): array
    {
        getimagesizefromstring($bytes, $info);

        return iptcparse($info['APP13']);
    }

    public function test_a_photo_goes_over_sftp_after_its_csv_with_the_pro_license(): void
    {
        $this->account();
        $work = $this->work();

        $this->send($work);

        $this->assertSame([['203.0.113.50', 22, false]], $this->sftp->connections);
        $this->assertSame([['akadrov', 'secret']], $this->sftp->logins);
        // The CSV first, so it is on the server when Vecteezy picks the photo up.
        $this->assertSame(['lagoon.csv', 'lagoon.jpg'], array_keys($this->sftp->files));
        $this->assertSame([
            ['Filename', 'Title', 'Description', 'Keywords', 'License'],
            ['lagoon', 'Icebergs in a glacier lagoon', 'Icebergs floating in a glacier lagoon at sunrise, Iceland', 'glacier,lagoon,iceberg,iceland,sunrise', 'pro'],
        ], $this->csv('lagoon.csv'));

        $iptc = $this->iptcOf($this->sftp->files['lagoon.jpg']);
        $this->assertSame('Icebergs in a glacier lagoon', $iptc['2#005'][0]);
        $this->assertSame(['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise'], $iptc['2#025']);

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame('lagoon.jpg', $listing->remote_filename);
        $this->assertSame(AssetStatus::Submitted, $work->fresh()->status);
    }

    public function test_an_editorial_png_goes_as_a_jpeg_without_spaces_in_its_name(): void
    {
        $this->account();

        $this->send($this->work(['type' => 'illustration', 'original_filename' => 'street parade.png', 'is_editorial' => true], [[AssetFileRole::Original, 'image/png', $this->image('png')]]));

        $this->assertSame(['street_parade.csv', 'street_parade.jpg'], array_keys($this->sftp->files));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->sftp->files['street_parade.jpg'])[2]);
        $this->assertSame(['street_parade', 'editorial'], array_values(array_intersect_key($this->csv('street_parade.csv')[1], [0 => 0, 4 => 4])));
        $this->assertSame('street_parade.jpg', AssetListing::sole()->remote_filename);
    }

    public function test_a_vector_goes_as_its_eps_and_jpeg_under_one_name(): void
    {
        $this->account();
        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n";

        $this->send($this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [
            [AssetFileRole::Original, 'application/postscript', $eps],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image()],
        ]));

        $this->assertSame(['logo.csv', 'logo.eps', 'logo.jpg'], array_keys($this->sftp->files));
        $this->assertSame($eps, $this->sftp->files['logo.eps']);
        $this->assertSame('logo', $this->csv('logo.csv')[1][0]);
        $this->assertSame('logo.eps', AssetListing::sole()->remote_filename);
    }

    public function test_works_vecteezy_never_takes_are_not_sent_and_say_why(): void
    {
        $account = $this->account();
        $svg = $this->work(['type' => 'vector', 'original_filename' => 'logo.svg'], [
            [AssetFileRole::Original, 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image()],
        ]);
        // 4 to 50 MP.
        $small = $this->work(['width' => 2000, 'height' => 1999]);
        $large = $this->work(['width' => 8700, 'height' => 5800]);
        $video = $this->work(['type' => 'video', 'original_filename' => 'surf.mov'], [[AssetFileRole::Original, 'video/quicktime', 'moov']]);

        $items = collect($this->getJson('/api/listings/batch')->assertOk()->json('data.items'))->keyBy('asset');
        $errors = fn (Asset $asset) => array_column($items[$asset->ulid]['accounts'][0]['errors'] ?? [], 'key');

        $this->assertSame(['format'], $errors($svg));
        $this->assertSame(['resolution_min'], $errors($small));
        $this->assertSame(['resolution_max'], $errors($large));
        $this->assertSame(['unsupported_type'], $errors($video));

        $this->postJson('/api/listings/batch', ['pairs' => array_map(fn (Asset $asset) => "{$asset->ulid}|{$account->id}", [$svg, $small, $large, $video])])
            ->assertAccepted()
            ->assertJsonPath('data.queued', []);

        $this->assertSame(0, AssetListing::count());
        $this->assertSame([], $this->sftp->files);
    }
}
