<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
use App\Models\AssetListing;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;

/**
 * 123RF over FTPS (ftp.123rf.com: the help page says FTP, but the server takes AUTH TLS; docs/marketplaces/123rf/README.md).
 * The login is the 123RF username, not the email. Files wait in the account until the contributor opens
 * Upload Content → Upload Via FTP and agrees to the terms; then they are drafts with the description and keywords
 * read from the IPTC.
 *
 * - Photo and illustration: an RGB JPEG in the root with the description and keywords inside (JpegMetadata). PNG,
 *   TIFF and CMYK go as a copy (MakesJpegCopies).
 * - Vector: the EPS and its JPEG (raster_companion) under the same name in the root, the metadata in the JPEG. Other
 *   vector formats are not taken (file_rules.formats_strict).
 */
class Rf123Delivery extends FtpDelivery
{
    use MakesJpegCopies;

    public function __construct(HostResolver $resolver, Container $container, protected JpegMetadata $metadata)
    {
        parent::__construct($resolver, $container);
    }

    public function package(AssetListing $listing, string $directory): array
    {
        $asset = $listing->asset;

        if ($asset->type === MediaType::Vector) {
            $name = RemoteFilename::for($listing, 'eps');
            $vector = $this->download($this->original($listing), "{$directory}/main");
            $companion = $this->jpeg($listing, $asset->file(AssetFileRole::RasterCompanion), "{$directory}/companion");

            return [
                new UploadFile($vector, $name),
                new UploadFile($companion, RemoteFilename::withExtension($name, 'jpg')),
            ];
        }

        $path = $this->jpeg($listing, $this->original($listing), "{$directory}/main");

        return [new UploadFile($path, RemoteFilename::for($listing, 'jpg'))];
    }
}
