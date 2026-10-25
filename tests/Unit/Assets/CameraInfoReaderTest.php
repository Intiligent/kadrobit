<?php

namespace Tests\Unit\Assets;

use App\Services\Assets\CameraInfoReader;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class CameraInfoReaderTest extends TestCase
{
    /**
     * A reader fed with given EXIF tags, as Imagick names them without the "exif:" prefix.
     *
     * @param  array<string, string>  $tags
     */
    protected function reader(array $tags): CameraInfoReader
    {
        return new class($tags) extends CameraInfoReader
        {
            public function __construct(protected array $tags) {}

            protected function exif(string $path): array
            {
                return $this->tags;
            }
        };
    }

    public function test_an_iphone_photo_is_summarized_in_a_form_to_show(): void
    {
        // Tags of IMG_6528.jpeg, iPhone 13 Pro.
        $camera = $this->reader([
            'Make' => 'Apple',
            'Model' => 'iPhone 13 Pro',
            'LensModel' => 'iPhone 13 Pro back triple camera 5.7mm f/1.5',
            'Software' => '18.5',
            'ExposureTime' => '1/3704',
            'FNumber' => '3/2',
            'PhotographicSensitivity' => '50',
            'FocalLength' => '57/10',
            'FocalLengthIn35mmFilm' => '26',
            'ExposureBiasValue' => '0/1',
            'ExposureProgram' => '2',
            'MeteringMode' => '5',
            'Flash' => '16',
            'WhiteBalance' => '0',
            'ColorSpace' => '65535',
            'GPSLatitude' => '41/1, 38/1, 183/100',
            'MakerNote' => '65, 112, 112',
        ])->read('photo.jpg');

        $this->assertSame([
            'make' => 'Apple',
            'model' => 'iPhone 13 Pro',
            'lens' => 'iPhone 13 Pro back triple camera 5.7mm f/1.5',
            'software' => '18.5',
            'exposure_time' => '1/3704',
            'f_number' => 1.5,
            'iso' => 50,
            'focal_length' => 5.7,
            'focal_length_35mm' => 26,
            'exposure_bias' => 0.0,
            'exposure_program' => 'auto',
            'metering' => 'pattern',
            'flash' => false,
            'white_balance' => 'auto',
            'color_space' => 'uncalibrated',
        ], $camera);
        // No GPS, no binary tags.
        $this->assertArrayNotHasKey('gps', $camera);
    }

    public function test_long_exposures_read_as_seconds_and_a_fired_flash_is_seen(): void
    {
        $camera = $this->reader([
            'ExposureTime' => '5/2',
            'ISOSpeedRatings' => '800',
            'ExposureBiasValue' => '-2/3',
            'Flash' => '25',
            'ExposureProgram' => '1',
            'WhiteBalance' => '1',
            'ColorSpace' => '1',
        ])->read('photo.jpg');

        $this->assertSame('2.5', $camera['exposure_time']);
        $this->assertSame(800, $camera['iso']);
        $this->assertSame(-0.67, $camera['exposure_bias']);
        $this->assertTrue($camera['flash']);
        $this->assertSame('manual', $camera['exposure_program']);
        $this->assertSame('manual', $camera['white_balance']);
        $this->assertSame('sRGB', $camera['color_space']);
    }

    public function test_unknown_codes_broken_values_and_empty_tags_are_left_out(): void
    {
        $camera = $this->reader([
            'Make' => "  \0",
            'FNumber' => '5/0',
            'ExposureProgram' => '42',
            'FocalLengthIn35mmFilm' => '0',
            'Model' => "\xFF\xFE",
        ])->read('photo.jpg');

        $this->assertSame([], $camera);
    }

    public function test_a_file_without_exif_or_one_imagick_cannot_read_has_no_summary(): void
    {
        $image = new Imagick;
        $image->newImage(20, 20, new ImagickPixel('orange'));
        $plain = tempnam(sys_get_temp_dir(), 'cam');
        $image->writeImage("jpeg:{$plain}");
        $broken = tempnam(sys_get_temp_dir(), 'cam');
        file_put_contents($broken, 'not an image');

        $this->assertSame([], (new CameraInfoReader)->read($plain));
        $this->assertSame([], (new CameraInfoReader)->read($broken));

        unlink($plain);
        unlink($broken);
    }
}
