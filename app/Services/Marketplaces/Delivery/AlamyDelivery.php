<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\AssetFileRole;
use App\Enums\MediaType;
use App\Models\AssetFile;
use App\Models\AssetListing;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;

/**
 * Alamy over plain FTP (upload.alamy.com: the server offers no AUTH TLS; docs/marketplaces/alamy/README.md). Files go
 * through Alamy's QC; captions and tags come from the IPTC inside the JPEG, the contributor finishes the rest in
 * Image Manager.
 *
 * - Photo: a baseline RGB JPEG in /Stock with the caption and keywords inside (JpegMetadata). PNG, TIFF, CMYK and
 *   progressive JPEG go as a copy (MakesJpegCopies).
 * - Vector: the EPS and its JPEG (raster_companion) under the same name in /Vectors; Alamy takes the caption and
 *   tags from the JPEG and nothing else from it. Other vector formats are not taken (file_rules.formats_strict).
 */
class AlamyDelivery extends FtpDelivery
{
    use MakesJpegCopies;

    public const STOCK_FOLDER = 'Stock';

    public const VECTORS_FOLDER = 'Vectors';

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
                new UploadFile($vector, $name, self::VECTORS_FOLDER),
                new UploadFile($companion, RemoteFilename::withExtension($name, 'jpg'), self::VECTORS_FOLDER),
            ];
        }

        $path = $this->jpeg($listing, $this->original($listing), "{$directory}/main");

        return [new UploadFile($path, RemoteFilename::for($listing, 'jpg'), self::STOCK_FOLDER)];
    }

    /** Alamy takes baseline JPEG only. */
    protected function needsCopy(AssetFile $file, string $path): bool
    {
        return $file->mime !== 'image/jpeg' || $this->isCmyk($path) || $this->isProgressive($path);
    }

    /**
     * Alamy fills its Caption (title, up to 128 characters) from the IPTC description and has no field for a
     * title in the file, so the work's title goes there. Its own Description, not searchable, is entered in Image
     * Manager.
     */
    protected function texts(AssetListing $listing): array
    {
        $texts = parent::texts($listing);

        return [...$texts, 'description' => $texts['title']];
    }

    /** Whether the JPEG's frame is progressive (SOF2), read from its markers up to the image data. */
    protected function isProgressive(string $path): bool
    {
        $file = fopen($path, 'rb');

        try {
            if (fread($file, 2) !== "\xFF\xD8") {
                return false;
            }

            while (($header = fread($file, 4)) !== false && strlen($header) === 4 && $header[0] === "\xFF") {
                $marker = ord($header[1]);

                if ($marker === 0xC2) {
                    return true;
                }

                // Another frame type or the image data: not progressive.
                if (in_array($marker, [0xC0, 0xC1, 0xC3, 0xDA], true)) {
                    return false;
                }

                fseek($file, unpack('n', substr($header, 2))[1] - 2, SEEK_CUR);
            }

            return false;
        } finally {
            fclose($file);
        }
    }
}
