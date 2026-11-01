<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
use App\Models\AssetListing;
use App\Services\Marketplaces\VecteezyCsv;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;

/**
 * Vecteezy over SFTP (content-ftp.eezy.com:22; the login and password are the contributor's own, from the Upload page;
 * docs/marketplaces/vecteezy/README.md). Files are processed on arrival: valid works go to Add Data, or straight to
 * review when the contributor has turned on Auto Submit.
 *
 * Each work goes with its own metadata CSV, sent ahead of its files so it is there when Vecteezy picks them up;
 * the same title, description and keywords are inside the JPEG too, in case the CSV is not read.
 *
 * - Photo and illustration: an sRGB JPEG in the root (MakesJpegCopies for PNG, TIFF and CMYK).
 * - Vector: the EPS and its JPEG (raster_companion) under the same name in the root.
 *
 * Vecteezy advises against spaces in file names: they become underscores.
 */
class VecteezyDelivery extends FtpDelivery
{
    use MakesJpegCopies;

    public function __construct(HostResolver $resolver, Container $container, protected JpegMetadata $metadata, protected VecteezyCsv $csv)
    {
        parent::__construct($resolver, $container);
    }

    public function package(AssetListing $listing, string $directory): array
    {
        $asset = $listing->asset;

        if ($asset->type === MediaType::Vector) {
            $name = $this->name($listing, 'eps');
            $vector = $this->download($this->original($listing), "{$directory}/main");
            $companion = $this->jpeg($listing, $asset->file(AssetFileRole::RasterCompanion), "{$directory}/companion");

            $files = [
                new UploadFile($vector, $name),
                new UploadFile($companion, RemoteFilename::withExtension($name, 'jpg')),
            ];
        } else {
            $name = $this->name($listing, 'jpg');
            $files = [new UploadFile($this->jpeg($listing, $this->original($listing), "{$directory}/main"), $name)];
        }

        file_put_contents("{$directory}/metadata.csv", $this->csv->build($name, $this->texts($listing), (bool) ($listing->metadata['is_editorial'] ?? false)));

        return [...$files, new UploadFile("{$directory}/metadata.csv", RemoteFilename::withExtension($name, 'csv'), ahead: true)];
    }

    protected function name(AssetListing $listing, string $extension): string
    {
        return str_replace(' ', '_', RemoteFilename::for($listing, $extension));
    }
}
