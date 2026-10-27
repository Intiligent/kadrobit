<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\AssetFileRole;
use App\Enums\ListingStatus;
use App\Enums\MediaType;
use App\Jobs\SendDreamstimeCsv;
use App\Models\AssetFile;
use App\Models\AssetListing;
use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\DreamstimeCsv;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use Throwable;

/**
 * Dreamstime over FTPS (upload.dreamstime.com, explicit TLS; docs/marketplaces/dreamstime/README.md). Files land in
 * Unfinished; the metadata CSV, the only way to set categories, goes at the end of the session and once more later
 * (SendDreamstimeCsv). The contributor submits the works on the website.
 *
 * - Photo and illustration: a JPEG in the root folder, with the title, description and keywords inside as well
 *   (JpegMetadata). Dreamstime takes RGB JPEG only: PNG, TIFF and CMYK go as an sRGB JPEG copy.
 * - Vector: its JPEG (raster_companion) in the root folder and the EPS or SVG under the same name in /additional.
 * - Video: the original in /video.
 */
class DreamstimeDelivery extends FtpDelivery
{
    /** JPEG quality of a copy made from another format: Dreamstime asks for the best compression quality. */
    public const JPEG_QUALITY = 95;

    public function __construct(HostResolver $resolver, Container $container, protected JpegMetadata $metadata, protected DreamstimeCsv $csv)
    {
        parent::__construct($resolver, $container);
    }

    public function package(AssetListing $listing, string $directory): array
    {
        $asset = $listing->asset;

        if ($asset->type === MediaType::Video) {
            $path = $this->download($this->original($listing), "{$directory}/main");

            return [new UploadFile($path, RemoteFilename::for($listing), 'video')];
        }

        if ($asset->type === MediaType::Vector) {
            $name = RemoteFilename::for($listing, 'jpg');
            $companion = $this->jpeg($listing, $asset->file(AssetFileRole::RasterCompanion), "{$directory}/companion");
            $vector = $this->download($this->original($listing), "{$directory}/main");
            $extension = strtolower(pathinfo($asset->original_filename, PATHINFO_EXTENSION)) ?: 'eps';

            // The JPEG is the submission (its name is the listing's); the vector is attached to it by the same name.
            return [
                new UploadFile($companion, $name),
                new UploadFile($vector, RemoteFilename::withExtension($name, $extension), 'additional'),
            ];
        }

        return [new UploadFile($this->jpeg($listing, $this->original($listing), "{$directory}/main"), RemoteFilename::for($listing, 'jpg'))];
    }

    /**
     * Sends the CSV of the uploaded works right away, in the same session, and queues it once more for later:
     * Dreamstime fills in only files it has already processed (SendDreamstimeCsv).
     */
    public function finished(FtpSession $session, array $listings): void
    {
        $account = $listings[0]->account;
        $ids = array_map(fn (AssetListing $listing) => $listing->id, $listings);
        $name = 'kadrobit-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));

        try {
            $this->uploadCsv($session, $this->sentListings($account, $ids), $name);
        } catch (Throwable $e) {
            // The later pass tries again.
            Log::warning("Dreamstime CSV for account {$account->id} was not sent with the files: {$e->getMessage()}");
        }

        try {
            SendDreamstimeCsv::afterSession($account, $ids, $name);
        } catch (Throwable $e) {
            Log::error("Dreamstime CSV for account {$account->id} was not queued: {$e->getMessage()}");
        }
    }

    /**
     * The account's listings among these that are sent and whose work still exists, with their work.
     *
     * @param  list<int>  $ids
     * @return Collection<int, AssetListing>
     */
    public function sentListings(MarketplaceAccount $account, array $ids): Collection
    {
        return $account->listings()
            ->whereKey($ids)
            ->where('status', ListingStatus::Sent)
            ->with('asset')
            ->orderBy('id')
            ->get()
            ->filter(fn (AssetListing $listing) => $listing->asset !== null)
            ->each(fn (AssetListing $listing) => $listing->setRelation('account', $account))
            ->values();
    }

    /**
     * Uploads the CSV of these sent listings with their works' current metadata (DreamstimeCsv) to the root folder,
     * and records that metadata as sent: the works are no longer shown as changed after sending.
     *
     * @param  Collection<int, AssetListing>  $listings
     *
     * @throws DeliveryFailed connect
     */
    public function uploadCsv(FtpSession $session, Collection $listings, string $name): void
    {
        if ($listings->isEmpty()) {
            return;
        }

        $marketplace = $listings->first()->account->marketplace;
        $directory = storage_path('app/private/publishing/'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            foreach (array_filter($this->csv->build($listings, $marketplace)) as $kind => $contents) {
                file_put_contents("{$directory}/{$kind}.csv", $contents);
                // Both in the root folder: the help names no other place for the video CSV.
                $session->upload(new UploadFile("{$directory}/{$kind}.csv", "{$name}-{$kind}.csv"));
            }
        } finally {
            File::deleteDirectory($directory);
        }

        foreach ($listings as $listing) {
            $listing->update(['metadata' => AssetListing::snapshot($listing->asset, $marketplace)]);
        }
    }

    /**
     * The work's image as an RGB JPEG with its metadata inside.
     *
     * @throws DeliveryFailed file, when the file cannot be read or converted
     */
    protected function jpeg(AssetListing $listing, ?AssetFile $file, string $path): string
    {
        $this->download($file, $path);

        try {
            if ($file->mime !== 'image/jpeg' || $this->needsRgb($path)) {
                $this->convert($path);
            }

            ['title' => $title, 'description' => $description, 'keywords' => $keywords] = $this->texts($listing);
            $this->metadata->embed($path, $title, $description, $keywords);
        } catch (Throwable $e) {
            throw new DeliveryFailed(AssetListing::ERROR_FILE, $e);
        }

        return $path;
    }

    protected function needsRgb(string $path): bool
    {
        $info = @getimagesize($path);

        // channels: 3 for RGB (and YCbCr), 4 for CMYK, 1 for greyscale, which marketplaces take as it is
        return ($info['channels'] ?? 3) === 4;
    }

    /**
     * Replaces the file with an sRGB JPEG of its first page, upright and on white where it was transparent.
     * Its EXIF, IPTC and XMP stay.
     */
    protected function convert(string $path): void
    {
        $image = new Imagick;

        try {
            $image->readImage($path.'[0]');
            $image->autoOrient();

            if ($image->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                // The CMYK profile no longer describes the pixels; without one, sRGB is assumed.
                $image->profileImage('icc', null);
            }

            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor(new ImagickPixel('white'));
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::JPEG_QUALITY);
            $image->writeImage('jpeg:'.$path);
        } finally {
            $image->clear();
        }
    }
}
