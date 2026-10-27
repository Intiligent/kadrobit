<?php

namespace Tests\Feature\Publishing;

use App\Enums\AssetFileRole;
use App\Enums\AssetStatus;
use App\Enums\ListingStatus;
use App\Enums\MarketplaceAccountStatus;
use App\Jobs\SendDreamstimeCsv;
use App\Models\Asset;
use App\Models\AssetListing;
use App\Models\Marketplace;
use App\Models\MarketplaceAccount;
use App\Models\User;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\DreamstimeDelivery;
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;
use App\Support\HostResolver;
use Database\Seeders\MarketplaceCategorySeeder;
use Database\Seeders\MarketplaceSeeder;
use Database\Seeders\OauthSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DreamstimeDeliveryTest extends TestCase
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
        $this->mock(HostResolver::class)->shouldReceive('publicAddress')->andReturn('203.0.113.20');

        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    protected function account(array $attributes = []): MarketplaceAccount
    {
        return $this->user->marketplaceAccounts()->create([
            'marketplace_id' => Marketplace::where('code', 'dreamstime')->value('id'),
            'label' => 'Dreamstime',
            'host' => 'upload.dreamstime.com',
            'contributor_id' => '61885241',
            'login' => '61885241',
            'credentials' => ['password' => 'secret'],
            'status' => MarketplaceAccountStatus::Active,
            ...$attributes,
        ]);
    }

    protected function image(string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kb-');
        $image = imagecreatetruecolor(2200, 1500);
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
            'description' => 'Icebergs floating in a glacier lagoon, at sunrise',
            'keywords' => ['glacier', 'lagoon', 'iceberg', 'iceland', 'sunrise', 'nature', 'ice'],
            'categories' => ['dreamstime' => ['146', '19']],
            'original_filename' => 'lagoon.jpg',
            'checksum' => hash('sha256', "dreamstime-{$n}"),
            'width' => 2200,
            'height' => 1500,
            ...$attributes,
        ]);

        foreach ($files ?? [[AssetFileRole::Original, 'image/jpeg', $this->image('jpeg')]] as [$role, $mime, $bytes]) {
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

    /** @return list<list<string>> */
    protected function rows(string $csv): array
    {
        return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", $csv))));
    }

    /** Uploaded files other than the CSV, by path on the server. @return array<string, string> */
    protected function works(): array
    {
        return array_filter($this->ftp->files, fn (string $path) => ! str_ends_with($path, '.csv'), ARRAY_FILTER_USE_KEY);
    }

    /** Paths of the uploaded CSV files. @return list<string> */
    protected function csvPaths(): array
    {
        return array_values(array_diff(array_keys($this->ftp->files), array_keys($this->works())));
    }

    protected function handle(SendDreamstimeCsv $job): void
    {
        $job->handle(app(DreamstimeDelivery::class));
    }

    public function test_a_jpeg_goes_to_the_root_folder_over_ftps_with_the_user_id_and_its_metadata_inside(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->account();

        $this->send($this->work());

        $this->assertSame([['203.0.113.20', 21, true]], $this->ftp->connections);
        $this->assertSame([['61885241', 'secret']], $this->ftp->logins);
        $this->assertSame(['lagoon.jpg'], array_keys($this->works()));

        $iptc = $this->iptcOf($this->ftp->files['lagoon.jpg']);
        $this->assertSame('Glacier lagoon', $iptc['2#005'][0]);
        $this->assertSame('Icebergs floating in a glacier lagoon, at sunrise', $iptc['2#120'][0]);

        $listing = AssetListing::sole();
        $this->assertSame(ListingStatus::Sent, $listing->status);
        $this->assertSame('lagoon.jpg', $listing->remote_filename);
        $this->assertSame(AssetStatus::Submitted, $listing->asset->fresh()->status);
    }

    public function test_the_csv_goes_right_after_the_files_in_the_same_session_and_once_more_later(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->seed(MarketplaceCategorySeeder::class);
        $this->account();
        $photo = $this->work(['is_editorial' => true]);
        $video = $this->work(['type' => 'video', 'original_filename' => 'surf.mov', 'categories' => []], [[AssetFileRole::Original, 'video/quicktime', 'moov']]);

        $this->send($photo, $video);

        // One sign-in: the files, then the CSV.
        $this->assertCount(1, $this->ftp->logins);
        $this->assertSame(['lagoon.jpg', 'video/surf.mov'], array_slice(array_keys($this->ftp->files), 0, 2));
        [$images, $videos] = $this->csvPaths();
        $this->assertMatchesRegularExpression('/^kadrobit-\d{8}-\d{6}-[a-z0-9]{6}-images\.csv$/', $images);
        $this->assertSame(str_replace('-images', '-videos', $images), $videos);

        $this->assertSame([
            ['Filename', 'Image Name', 'Description', 'Category 1', 'Category 2', 'Category 3', 'keywords', 'Free', 'W-EL', 'P-EL', 'SR-EL', 'SR-Price', 'Editorial', 'MR doc Ids', 'Pr Docs'],
            ['lagoon.jpg', 'Glacier lagoon', 'Icebergs floating in a glacier lagoon, at sunrise', '146', '19', '0', 'glacier,lagoon,iceberg,iceland,sunrise,nature,ice', '0', '0', '0', '0', '0', '1', '', ''],
        ], $this->rows($this->ftp->files[$images]));
        $this->assertSame(
            ['surf.mov', 'Glacier lagoon', 'Icebergs floating in a glacier lagoon, at sunrise', '0', '0', '0', 'glacier,lagoon,iceberg,iceland,sunrise,nature,ice', '0', '0', '0', '0', '', ''],
            $this->rows($this->ftp->files[$videos])[1],
        );

        // Once more later under the same name, for files Dreamstime had not processed yet.
        Queue::assertPushed(SendDreamstimeCsv::class, fn (SendDreamstimeCsv $job) => $job->listingIds === AssetListing::orderBy('id')->pluck('id')->all()
            && $job->name.'-images.csv' === $images
            && $job->pass === 0
            && $job->delay === config('publishing.dreamstime.csv_delays')[0]);
    }

    public function test_the_csv_takes_the_current_metadata_and_records_it_as_sent(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $account = $this->account();
        $work = $this->work(['categories' => []]);
        $this->send($work);
        $listing = AssetListing::sole();
        // A category chosen after the files went.
        $work->update(['categories' => ['dreamstime' => ['28']]]);
        $this->assertTrue($listing->fresh()->load('asset', 'account.marketplace')->isOutdated());
        $this->ftp->files = [];

        $this->handle(new SendDreamstimeCsv($account, [$listing->id], 'kadrobit-test'));

        $this->assertSame('28', $this->rows($this->ftp->files['kadrobit-test-images.csv'])[1][3]);
        $this->assertSame(['28'], $listing->fresh()->metadata['categories']);
        $this->assertFalse($listing->fresh()->load('asset', 'account.marketplace')->isOutdated());
    }

    public function test_a_png_goes_as_a_jpeg_copy(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->account();

        $this->send($this->work(['original_filename' => 'lagoon.png'], [[AssetFileRole::Original, 'image/png', $this->image('png')]]));

        $this->assertSame(['lagoon.jpg'], array_keys($this->works()));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->ftp->files['lagoon.jpg'])[2]);
        $this->assertSame('Glacier lagoon', $this->iptcOf($this->ftp->files['lagoon.jpg'])['2#005'][0]);
        $this->assertSame('lagoon.jpg', AssetListing::sole()->remote_filename);
    }

    public function test_a_cmyk_jpeg_goes_as_an_rgb_copy(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->account();
        $image = new \Imagick;
        $image->newImage(2200, 1500, 'cmyk(100%,0,0,0)');
        $image->setImageColorspace(\Imagick::COLORSPACE_CMYK);
        $image->setImageFormat('jpeg');
        $cmyk = $image->getImageBlob();
        $this->assertSame(4, getimagesizefromstring($cmyk)['channels']);

        $this->send($this->work([], [[AssetFileRole::Original, 'image/jpeg', $cmyk]]));

        $this->assertSame(3, getimagesizefromstring($this->ftp->files['lagoon.jpg'])['channels']);
    }

    public function test_a_vector_goes_as_its_jpeg_in_the_root_and_the_eps_in_additional(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->account();
        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n";

        $this->send($this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [
            [AssetFileRole::Original, 'application/postscript', $eps],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image('jpeg')],
        ]));

        $this->assertSame(['logo.jpg', 'additional/logo.eps'], array_keys($this->works()));
        $this->assertSame($eps, $this->ftp->files['additional/logo.eps']);
        $this->assertSame('Glacier lagoon', $this->iptcOf($this->ftp->files['logo.jpg'])['2#005'][0]);
        $this->assertSame('logo.jpg', AssetListing::sole()->remote_filename);
    }

    public function test_a_vector_jpeg_never_takes_the_name_of_another_works_jpeg(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->account();
        $photo = $this->work(['original_filename' => 'logo.jpg']);
        $this->send($photo);

        $vector = $this->work(['type' => 'vector', 'original_filename' => 'logo.eps'], [
            [AssetFileRole::Original, 'application/postscript', "%!PS-Adobe-3.0 EPSF-3.0\n"],
            [AssetFileRole::RasterCompanion, 'image/jpeg', $this->image('jpeg')],
        ]);
        $this->send($vector);

        $suffix = strtolower(substr($vector->ulid, -4));
        $this->assertSame("logo-{$suffix}.jpg", $vector->listings()->sole()->remote_filename);
        $this->assertArrayHasKey("additional/logo-{$suffix}.eps", $this->ftp->files);
    }

    public function test_a_video_goes_to_the_video_folder(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $this->account();

        $this->send($this->work(['type' => 'video', 'original_filename' => 'surf.mov'], [[AssetFileRole::Original, 'video/quicktime', 'moov']]));

        $this->assertSame(['video/surf.mov' => 'moov'], $this->works());
        $this->assertSame('surf.mov', AssetListing::sole()->remote_filename);
    }

    public function test_the_later_pass_skips_listings_that_are_not_sent_and_a_rejected_login_is_not_retried(): void
    {
        Queue::fake([SendDreamstimeCsv::class]);
        $account = $this->account();
        $this->send($this->work());
        $failed = $this->work(['original_filename' => 'other.jpg']);
        $failedListing = AssetListing::create(['asset_id' => $failed->id, 'marketplace_account_id' => $account->id, 'status' => ListingStatus::Failed, 'remote_filename' => 'other.jpg']);
        $ids = AssetListing::orderBy('id')->pluck('id')->all();
        $this->ftp->files = [];

        // The last pass: nothing more is queued.
        $this->handle(new SendDreamstimeCsv($account, $ids, 'kadrobit-test'));
        $rows = $this->rows($this->ftp->files['kadrobit-test-images.csv']);
        $this->assertSame(['lagoon.jpg'], array_column(array_slice($rows, 1), 0));
        $this->assertNotContains($failedListing->remote_filename, array_column($rows, 0));
        Queue::assertPushed(SendDreamstimeCsv::class, 1);

        $this->ftp->loginFailure = ConnectionResult::AUTH;
        $this->ftp->files = [];
        $this->handle(new SendDreamstimeCsv($account, $ids, 'kadrobit-test'));
        $this->assertSame([], $this->ftp->files);
        Queue::assertPushed(SendDreamstimeCsv::class, 1);
    }

    public function test_dreamstime_categories_are_seeded_by_their_numbers(): void
    {
        $this->seed(MarketplaceCategorySeeder::class);
        $categories = Marketplace::where('code', 'dreamstime')->sole()->categories();

        $this->assertSame(182, $categories->count());
        $this->assertSame('Nature → Landscapes', $categories->where('external_id', '146')->value('name'));
    }
}
