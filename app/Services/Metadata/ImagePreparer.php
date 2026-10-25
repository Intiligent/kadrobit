<?php

namespace App\Services\Metadata;

use App\Enums\AssetFileRole;
use App\Models\Asset;
use Imagick;

/**
 * The image a provider gets: an sRGB JPEG without transparency, no larger than config('metadata.image_size')
 * on its longest side.
 * Image tokens grow with the area, and the preview (1600 px) is more than metadata needs.
 */
class ImagePreparer
{
    public function fromAsset(Asset $asset): string
    {
        $file = $asset->file(AssetFileRole::Preview) ?? throw new GenerationException("Work {$asset->ulid} has no preview yet.");

        return $this->fromBytes($file->storage()->get($file->path));
    }

    public function fromBytes(string $bytes): string
    {
        $size = (int) config('metadata.image_size');
        $image = new Imagick;

        try {
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            $image->autoOrient();

            if (max($image->getImageWidth(), $image->getImageHeight()) > $size) {
                $image->thumbnailImage($size, $size, true);
            }

            // JPEG has no alpha: transparent areas would turn black and the model would describe a dark scene.
            if ($image->getImageAlphaChannel()) {
                $image->setImageBackgroundColor('white');
                $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }

            // Before stripImage(): without its profile a CMYK image reaches the model with wrong colors.
            if ($image->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);

            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
    }
}
