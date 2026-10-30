<?php

namespace App\Services\Marketplaces\Delivery;

use App\Models\AssetFile;
use App\Models\AssetListing;
use Imagick;
use ImagickPixel;
use Throwable;

/**
 * For a delivery that takes images as RGB JPEG only (Dreamstime, Alamy): the work's image as a JPEG copy with its
 * metadata inside (JpegMetadata). PNG, TIFF and CMYK become a baseline sRGB JPEG; a JPEG that needs no change is
 * sent as it is, so it is not compressed twice. Needs a JpegMetadata in $this->metadata.
 */
trait MakesJpegCopies
{
    /** JPEG quality of a copy made from another format: marketplaces ask for the best compression quality. */
    public const JPEG_QUALITY = 95;

    /**
     * @throws DeliveryFailed file, when the file cannot be read or converted
     */
    protected function jpeg(AssetListing $listing, ?AssetFile $file, string $path): string
    {
        $this->download($file, $path);

        try {
            if ($this->needsCopy($file, $path)) {
                $this->convert($path);
            }

            ['title' => $title, 'description' => $description, 'keywords' => $keywords] = $this->texts($listing);
            $this->metadata->embed($path, $title, $description, $keywords);
        } catch (Throwable $e) {
            throw new DeliveryFailed(AssetListing::ERROR_FILE, $e);
        }

        return $path;
    }

    /** Whether the downloaded file has to be converted before it goes. */
    protected function needsCopy(AssetFile $file, string $path): bool
    {
        return $file->mime !== 'image/jpeg' || $this->isCmyk($path);
    }

    protected function isCmyk(string $path): bool
    {
        $info = @getimagesize($path);

        // channels: 3 for RGB (and YCbCr), 4 for CMYK, 1 for greyscale, which marketplaces take as it is
        return ($info['channels'] ?? 3) === 4;
    }

    /**
     * Replaces the file with a baseline sRGB JPEG of its first page, upright and on white where it was transparent.
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
            // A progressive source would stay progressive otherwise.
            $image->setInterlaceScheme(Imagick::INTERLACE_NO);
            $image->writeImage('jpeg:'.$path);
        } finally {
            $image->clear();
        }
    }
}
