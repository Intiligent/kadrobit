<?php

namespace App\Services\Assets;

use App\Enums\AssetFileRole;
use DOMDocument;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Imagick;
use ImagickException;
use ImagickPixel;
use RuntimeException;

/**
 * Downscaled JPEG copies for the browser: a thumbnail for the library grid and a larger preview for
 * the work's panel (and AI metadata later). Made from the local file before anything goes to storage:
 * from a photo itself, from a video's frame (VideoTools), from a vector rasterized into its
 * raster companion, the full-size JPEG some marketplaces take with the vector.
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

    /** The raster companion is sent to marketplaces, not just looked at. */
    public const COMPANION_QUALITY = 92;

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

        $this->limitResources();

        $image = new Imagick;
        $copies = [];

        try {
            // Only the first page or frame of TIFF, GIF and the like.
            $this->read($image, $source);
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
     * Rasterizes an EPS or SVG once, at about raster_companion_mp megapixels, into its raster companion,
     * and makes the thumbnail and the preview from that. The size is the vector's own, as Imagick reads
     * it at its default density (72 dpi for EPS): marketplaces judge vectors by it.
     *
     * @return array{width: int, height: int, copies: list<array{role: AssetFileRole, path: string, mime: string, size_bytes: int, width: int, height: int, checksum: string}>}
     */
    public function forVector(string $source, string $mime): array
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('The imagick PHP extension is required for previews.');
        }

        if ($mime === 'image/svg+xml') {
            $this->assertSafeSvg($source);
        }

        $this->limitResources();

        [$width, $height, $baseDensity] = $mime === 'image/svg+xml' ? $this->svgSize($source) : $this->epsSize($source);

        if ($width < 1 || $height < 1) {
            throw new UnreadableMedia('The vector has no size.');
        }

        // The scale that brings the artboard to the target pixel count, up or down.
        $scale = sqrt(config('assets.raster_companion_mp') * 1_000_000 / ($width * $height));

        $image = new Imagick;
        $copies = [];
        $rendered = null;

        try {
            // Rendered outside Imagick, under a timeout: librsvg draws gradients, masks and filters that
            // Imagick's own SVG renderer drops, and Ghostscript can hang on a malformed EPS.
            $rendered = $mime === 'image/svg+xml'
                ? $this->renderSvg($source, (int) round($width * $scale), (int) round($height * $scale))
                : $this->renderEps($source, $baseDensity * $scale);
            $this->read($image, $rendered);

            $this->normalize($image);

            $copies[] = $this->copy($image, AssetFileRole::RasterCompanion, null, self::COMPANION_QUALITY);

            foreach (self::SIZES as $role => $size) {
                $copies[] = $this->copy($image, AssetFileRole::from($role), $size);
            }
        } catch (\Throwable $e) {
            $this->discard($copies);

            throw $e;
        } finally {
            $image->clear();

            if ($rendered) {
                @unlink($rendered);
            }
        }

        return ['width' => $width, 'height' => $height, 'copies' => $copies];
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

    /**
     * Several upload workers run side by side: each Imagick keeps to one thread and a memory budget
     * (config/assets.php), so they do not fight over the cores or run the server out of memory.
     */
    protected function limitResources(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, max(1, config('assets.imagick_threads')));
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, config('assets.imagick_memory_mb') * 1024 * 1024);
    }

    /**
     * Reads the first page or frame. A file Imagick (or its Ghostscript delegate) cannot read
     * will not read on a retry either.
     */
    protected function read(Imagick $image, string $source, bool $ping = false): void
    {
        try {
            $ping ? $image->pingImage($source.'[0]') : $image->readImage($source.'[0]');
        } catch (ImagickException $e) {
            throw new UnreadableMedia($e->getMessage(), previous: $e);
        }
    }

    /**
     * Width and height from the SVG's own width/height or viewBox, as Imagick reads them.
     *
     * @return array{0: int, 1: int, 2: float}
     */
    protected function svgSize(string $source): array
    {
        $probe = new Imagick;

        try {
            $this->read($probe, $source, ping: true);

            return [$probe->getImageWidth(), $probe->getImageHeight(), 72.0];
        } finally {
            $probe->clear();
        }
    }

    /**
     * The EPS bounding box in points (pixels at 72 dpi): a ping reports the page size instead.
     * Without a bounding box comment, the file is read once at 72 dpi.
     *
     * @return array{0: int, 1: int, 2: float}
     */
    protected function epsSize(string $source): array
    {
        $head = (string) file_get_contents($source, length: 65536);

        foreach (['%%HiResBoundingBox:', '%%BoundingBox:'] as $comment) {
            $number = '(-?\d+(?:\.\d+)?)';

            if (preg_match('/^'.preg_quote($comment, '/')."\s*{$number}\s+{$number}\s+{$number}\s+{$number}/m", $head, $box)) {
                $width = (int) round($box[3] - $box[1]);
                $height = (int) round($box[4] - $box[2]);

                if ($width > 0 && $height > 0) {
                    return [$width, $height, 72.0];
                }
            }
        }

        $rendered = $this->renderEps($source, 72);

        try {
            $size = @getimagesize($rendered);
        } finally {
            @unlink($rendered);
        }

        if (! $size) {
            throw new UnreadableMedia('The EPS has no size.');
        }

        return [$size[0], $size[1], 72.0];
    }

    /**
     * Runs a renderer under the vector timeout. A file that hangs it will hang it on every retry.
     *
     * @param  list<string>  $command
     */
    protected function render(array $command): ProcessResult
    {
        try {
            return Process::timeout(config('assets.vector_timeout'))->run($command);
        } catch (ProcessTimedOutException $e) {
            throw new UnreadableMedia('Rendering took longer than '.config('assets.vector_timeout').' s.', previous: $e);
        }
    }

    /**
     * PNG of the EPS, cropped to its bounding box, at this many dots per inch, by Ghostscript with
     * -dSAFER (no file access beyond its input and output). CMYK comes out as RGB.
     */
    protected function renderEps(string $source, float $density): string
    {
        $output = tempnam(sys_get_temp_dir(), 'eps');

        $result = $this->render([
            config('assets.ghostscript'), '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-dEPSCrop',
            '-sDEVICE=png16m', '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            '-r'.sprintf('%.3F', max(1, $density)), '-sOutputFile='.$output, $source,
        ]);

        // Ghostscript may exit with 0 after a PostScript error: the output tells.
        if ($result->failed() || ! filesize($output) || ! @getimagesize($output)) {
            @unlink($output);

            throw new UnreadableMedia('Ghostscript failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $output;
    }

    /**
     * PNG of the SVG at exactly this size, by rsvg-convert. Transparency stays; normalize() puts it on white.
     */
    protected function renderSvg(string $source, int $width, int $height): string
    {
        $output = tempnam(sys_get_temp_dir(), 'svg');

        $result = $this->render([
            config('assets.rsvg_convert'), '--width', (string) max(1, $width), '--height', (string) max(1, $height),
            '--format', 'png', '--output', $output, $source,
        ]);

        if ($result->failed() || ! filesize($output)) {
            @unlink($output);

            throw new UnreadableMedia('rsvg-convert failed: '.trim($result->errorOutput()));
        }

        return $output;
    }

    /**
     * The rasterizer would follow links out of an SVG: local files, URLs, entities. Only
     * self-contained drawings are rasterized: no DOCTYPE, and references only within the file
     * (#id) or to embedded data (data:).
     */
    protected function assertSafeSvg(string $source): void
    {
        $xml = file_get_contents($source);

        if ($xml === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new UnreadableMedia('The SVG declares a DOCTYPE or entities.');
        }

        $document = new DOMDocument;

        if (! @$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->localName !== 'svg') {
            throw new UnreadableMedia('The file is not a readable SVG.');
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                $value = trim($attribute->value);
                $isLink = in_array($attribute->localName, ['href', 'src'], true);

                if ($isLink && $value !== '' && ! str_starts_with($value, '#') && ! str_starts_with($value, 'data:')) {
                    throw new UnreadableMedia("The SVG links to {$value}.");
                }

                if (preg_match('/url\(\s*+[\'"]?+\s*+(?!#|data:)[^)\s]/i', $value)) {
                    throw new UnreadableMedia('The SVG style links outside the file.');
                }
            }

            if (in_array($element->localName, ['style'], true) && preg_match('/@import|url\(\s*+[\'"]?+\s*+(?!#|data:)[^)\s]/i', $element->textContent)) {
                throw new UnreadableMedia('The SVG style links outside the file.');
            }
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
     * A JPEG of the image, fitted into `$size` pixels on the long side (null: as it is).
     *
     * @return array{role: AssetFileRole, path: string, mime: string, size_bytes: int, width: int, height: int, checksum: string}
     */
    protected function copy(Imagick $source, AssetFileRole $role, ?int $size, int $quality = self::QUALITY): array
    {
        $image = clone $source;
        $path = null;

        try {
            if ($size && max($image->getImageWidth(), $image->getImageHeight()) > $size) {
                $image->thumbnailImage($size, $size, true);
            }

            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality($quality);
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
