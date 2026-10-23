<?php

namespace App\Services\Assets;

use App\Enums\AssetFileRole;
use Imagick;
use ImagickPixel;
use RuntimeException;

/**
 * Downscaled JPEG copies of a photo for the browser: a thumbnail for the library grid and a larger
 * preview for the work's panel (and AI metadata later). Made from the local file before anything
 * goes to storage. Videos and vectors get theirs in a later stage.
 *
 * Every copy is turned upright by its EXIF orientation, converted to sRGB (the colour space browsers
 * assume), flattened onto white when it has transparency and stripped of metadata.
 */
class PreviewGenerator
{
    /**
     * Longest side in pixels, per role. Smaller images are not enlarged.
     *
     * @var array<string, int>
     */
    public const SIZES = [
        'thumbnail' => 480,
        'preview' => 1600,
    ];

    public const QUALITY = 82;

    /**
     * Writes the copies next to each other in the temp directory; the caller stores and deletes them.
     *
     * @return list<array{role: AssetFileRole, path: string, mime: string, size_bytes: int, width: int, height: int, checksum: string}>
     */
    public function forPhoto(string $source): array
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('The imagick PHP extension is required for previews.');
        }

        $image = new Imagick;
        $copies = [];

        try {
            // Only the first page or frame of TIFF, GIF and the like.
            $image->readImage($source.'[0]');
            $this->normalize($image);

            foreach (self::SIZES as $role => $size) {
                $copies[] = $this->copy($image, AssetFileRole::from($role), $size);
            }
        } catch (\Throwable $e) {
            $this->discard($copies);

            throw $e;
        } finally {
            $image->clear();
        }

        return $copies;
    }

    /**
     * Removes the temp files of copies that were not stored.
     *
     * @param  list<array{path: string}>  $copies
     */
    public function discard(array $copies): void
    {
        foreach ($copies as $copy) {
            @unlink($copy['path']);
        }
    }

    protected function normalize(Imagick $image): void
    {
        $image->autoOrient();

        if ($image->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }

        if ($image->getImageAlphaChannel()) {
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }

        $image->stripImage();
    }

    /**
     * @return array{role: AssetFileRole, path: string, mime: string, size_bytes: int, width: int, height: int, checksum: string}
     */
    protected function copy(Imagick $source, AssetFileRole $role, int $size): array
    {
        $image = clone $source;
        $path = null;

        try {
            if (max($image->getImageWidth(), $image->getImageHeight()) > $size) {
                $image->thumbnailImage($size, $size, true);
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::QUALITY);
            $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);

            $path = tempnam(sys_get_temp_dir(), $role->value);
            $image->writeImage('jpeg:'.$path);

            return [
                'role' => $role,
                'path' => $path,
                'mime' => 'image/jpeg',
                'size_bytes' => filesize($path),
                'width' => $image->getImageWidth(),
                'height' => $image->getImageHeight(),
                'checksum' => hash_file('sha256', $path),
            ];
        } catch (\Throwable $e) {
            // The copy is not returned, so nobody else would remove its temp file.
            if ($path) {
                @unlink($path);
            }

            throw $e;
        } finally {
            $image->clear();
        }
    }
}
