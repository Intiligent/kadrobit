<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
use App\Models\AssetFile;
use App\Models\AssetListing;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Shutterstock over FTPS (ftps.shutterstock.com, explicit TLS; docs/publishing.md). Files land in "To submit";
 * the contributor submits them on the website.
 *
 * - JPEG: a copy with the description and keywords inside (IPTC and XMP, JpegMetadata). Shutterstock has one text
 *   field, so the description goes into the title fields too. Categories cannot be embedded: they are chosen on
 *   the website (KB-54) or sent by CSV (ShutterstockCsv).
 * - Vector: the EPS and its JPEG (raster_companion) under the same name, the metadata in the JPEG.
 * - TIFF and video: the original as it is; their metadata goes by CSV or on the website.
 */
class ShutterstockDelivery extends FtpDelivery
{
    public function __construct(HostResolver $resolver, Container $container, protected JpegMetadata $metadata)
    {
        parent::__construct($resolver, $container);
    }

    public function package(AssetListing $listing, string $directory): array
    {
        $name = RemoteFilename::for($listing);
        $original = $this->original($listing);
        $files = [new UploadFile($this->prepare($listing, $original, "{$directory}/main"), $name)];

        if ($listing->asset->type === MediaType::Vector) {
            $companion = $listing->asset->file(AssetFileRole::RasterCompanion);

            if ($companion) {
                $files[] = new UploadFile($this->prepare($listing, $companion, "{$directory}/companion"), RemoteFilename::withExtension($name, 'jpg'));
            }
        }

        return $files;
    }

    /**
     * @throws DeliveryFailed file, when the work's file cannot be read or is not the JPEG it claims to be
     */
    protected function prepare(AssetListing $listing, ?AssetFile $file, string $path): string
    {
        $this->download($file, $path);

        if ($file->mime === 'image/jpeg') {
            ['title' => $title, 'description' => $description, 'keywords' => $keywords] = $this->texts($listing);

            try {
                $this->metadata->embed($path, $title, $description, $keywords);
            } catch (Throwable $e) {
                throw new DeliveryFailed(AssetListing::ERROR_FILE, $e);
            }
        }

        return $path;
    }
}
