<?php

namespace App\Services\Assets;

use Imagick;
use ImagickException;

/**
 * A summary of how a photo was taken, from its EXIF, for assets.media_info.camera (KB-46): camera, lens and
 * exposure, with values already in a form to show ("11/5" → 2.2, codes → words). Not the whole EXIF: binary tags
 * (MakerNote, the thumbnail) are left out, and so is GPS, which is not shown.
 *
 * Imagick reads the EXIF of JPEG, TIFF, HEIC, PNG and WebP with the same tag names; pingImage() reads only the
 * metadata, not the pixels.
 */
class CameraInfoReader
{
    /** EXIF ExposureProgram. */
    protected const PROGRAMS = [1 => 'manual', 2 => 'auto', 3 => 'aperture_priority', 4 => 'shutter_priority', 5 => 'creative', 6 => 'action', 7 => 'portrait', 8 => 'landscape'];

    /** EXIF MeteringMode. */
    protected const METERING = [1 => 'average', 2 => 'center_weighted', 3 => 'spot', 4 => 'multi_spot', 5 => 'pattern', 6 => 'partial'];

    /**
     * @return array{make?: string, model?: string, lens?: string, software?: string, exposure_time?: string, f_number?: float, iso?: int, focal_length?: float, focal_length_35mm?: int, exposure_bias?: float, exposure_program?: string, metering?: string, flash?: bool, white_balance?: string, color_space?: string}
     */
    public function read(string $path): array
    {
        $exif = $this->exif($path);

        if (! $exif) {
            return [];
        }

        $flash = $this->int($exif['Flash'] ?? null);
        $whiteBalance = $this->int($exif['WhiteBalance'] ?? null);
        $colorSpace = $this->int($exif['ColorSpace'] ?? null);

        return array_filter([
            'make' => $this->text($exif['Make'] ?? null),
            'model' => $this->text($exif['Model'] ?? null),
            'lens' => $this->text($exif['LensModel'] ?? null),
            'software' => $this->text($exif['Software'] ?? null),
            'exposure_time' => $this->exposureTime($exif['ExposureTime'] ?? null),
            'f_number' => $this->round($this->number($exif['FNumber'] ?? null), 1),
            'iso' => $this->int($exif['PhotographicSensitivity'] ?? $exif['ISOSpeedRatings'] ?? null),
            'focal_length' => $this->round($this->number($exif['FocalLength'] ?? null), 2),
            'focal_length_35mm' => $this->int($exif['FocalLengthIn35mmFilm'] ?? null) ?: null,
            'exposure_bias' => $this->round($this->number($exif['ExposureBiasValue'] ?? null), 2),
            'exposure_program' => self::PROGRAMS[$this->int($exif['ExposureProgram'] ?? null)] ?? null,
            'metering' => self::METERING[$this->int($exif['MeteringMode'] ?? null)] ?? null,
            // Bit 0: the flash fired.
            'flash' => $flash === null ? null : (bool) ($flash & 1),
            'white_balance' => match ($whiteBalance) {
                0 => 'auto', 1 => 'manual', default => null
            },
            // 65535 "uncalibrated": usually a wide gamut such as Display P3 on iPhones, named by the ICC profile.
            'color_space' => match ($colorSpace) {
                1 => 'sRGB', 2 => 'Adobe RGB', 65535 => 'uncalibrated', default => null
            },
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, string> EXIF tags without the "exif:" prefix
     */
    protected function exif(string $path): array
    {
        if (! extension_loaded('imagick')) {
            return [];
        }

        $image = new Imagick;

        try {
            $image->pingImage($path);
            $exif = [];

            foreach ($image->getImageProperties('exif:*') as $name => $value) {
                $exif[substr($name, 5)] = $value;
            }

            return $exif;
        } catch (ImagickException) {
            // A file Imagick cannot read has no camera summary; the upload goes on.
            return [];
        } finally {
            $image->clear();
        }
    }

    /**
     * "1/140" stays a fraction; longer exposures read as seconds: "2.5 s" → "2.5".
     */
    protected function exposureTime(?string $value): ?string
    {
        $seconds = $this->number($value);

        if (! $seconds) {
            return null;
        }

        return $seconds < 1 ? '1/'.round(1 / $seconds) : (string) $this->round($seconds, 1);
    }

    /**
     * An EXIF rational ("11/5") or a plain number; the first one of a list.
     */
    protected function number(?string $value): ?float
    {
        $value = trim(explode(',', (string) $value)[0]);

        if (preg_match('#^(-?\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$#', $value, $match)) {
            return (float) $match[2] === 0.0 ? null : (float) $match[1] / (float) $match[2];
        }

        return is_numeric($value) ? (float) $value : null;
    }

    protected function int(?string $value): ?int
    {
        $number = $this->number($value);

        return $number === null ? null : (int) round($number);
    }

    protected function round(?float $value, int $precision): ?float
    {
        return $value === null ? null : round($value, $precision);
    }

    protected function text(?string $value): ?string
    {
        $value = trim((string) $value, " \0");

        return $value === '' || ! mb_check_encoding($value, 'UTF-8') ? null : mb_substr($value, 0, 100);
    }
}
