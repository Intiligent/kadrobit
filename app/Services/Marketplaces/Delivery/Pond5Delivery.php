<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\MediaType;
use App\Models\AssetListing;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;

/**
 * Pond5 over FTPS (ftp.pond5.com: the server takes AUTH TLS; docs/marketplaces/pond5/README.md). The login is the
 * Pond5 username, the password a separate one for the FTP uploader set in the contributor area. Files land in the
 * root and show under Uploads within minutes; the contributor fills in the details and submits them on the website.
 *
 * - Photo: an RGB JPEG with the title, description and keywords inside (JpegMetadata), in case Pond5 keeps them.
 *   PNG, TIFF and CMYK go as a copy (MakesJpegCopies): Pond5 rejects CMYK and takes PNG only with transparency.
 * - Video: the original as it is (MOV or MP4).
 *
 * Pond5 takes file names of letters, digits and underscores only (its guides forbid spaces and dashes), so the name
 * is cut down to those (pond5Name()), before the check for a name already in use.
 */
class Pond5Delivery extends FtpDelivery
{
    use MakesJpegCopies;

    public function __construct(HostResolver $resolver, Container $container, protected JpegMetadata $metadata)
    {
        parent::__construct($resolver, $container);
    }

    public function package(AssetListing $listing, string $directory): array
    {
        if ($listing->asset->type === MediaType::Video) {
            $path = $this->download($this->original($listing), "{$directory}/main");

            return [new UploadFile($path, RemoteFilename::for($listing, null, self::pond5Name(...)))];
        }

        $path = $this->jpeg($listing, $this->original($listing), "{$directory}/main");

        return [new UploadFile($path, RemoteFilename::for($listing, 'jpg', self::pond5Name(...)))];
    }

    /**
     * The name with everything but letters, digits and underscores in its base turned into underscores:
     * "Sunset over lake (2)-7f3a.jpg" becomes "Sunset_over_lake_2_7f3a.jpg".
     */
    public static function pond5Name(string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = trim(preg_replace('/[^A-Za-z0-9_]+/', '_', pathinfo($name, PATHINFO_FILENAME)), '_');

        return ($base !== '' ? $base : 'work').($extension !== '' ? ".{$extension}" : '');
    }
}
