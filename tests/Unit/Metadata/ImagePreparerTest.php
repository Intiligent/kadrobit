<?php

namespace Tests\Unit\Metadata;

use App\Services\Metadata\ImagePreparer;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class ImagePreparerTest extends TestCase
{
    protected function prepare(Imagick $source, string $format): Imagick
    {
        $source->setImageFormat($format);
        $image = new Imagick;
        $image->readImageBlob((new ImagePreparer)->fromBytes($source->getImageBlob()));

        return $image;
    }

    public function test_large_images_are_downscaled_to_a_jpeg(): void
    {
        $source = new Imagick;
        $source->newImage(3000, 2000, new ImagickPixel('orange'));

        $image = $this->prepare($source, 'png');

        $this->assertSame('JPEG', $image->getImageFormat());
        $this->assertSame([1024, 683], [$image->getImageWidth(), $image->getImageHeight()]);
    }

    public function test_transparency_becomes_white_not_black(): void
    {
        $source = new Imagick;
        $source->newImage(200, 200, new ImagickPixel('transparent'));

        $pixel = $this->prepare($source, 'png')->getImagePixelColor(100, 100)->getColor();

        $this->assertGreaterThan(250, $pixel['r']);
        $this->assertGreaterThan(250, $pixel['b']);
    }

    public function test_cmyk_is_converted_to_srgb(): void
    {
        $source = new Imagick;
        $source->newImage(200, 200, new ImagickPixel('red'));
        $source->transformImageColorspace(Imagick::COLORSPACE_CMYK);

        $image = $this->prepare($source, 'jpeg');

        $this->assertSame(Imagick::COLORSPACE_SRGB, $image->getImageColorspace());
        $this->assertGreaterThan(200, $image->getImagePixelColor(100, 100)->getColor()['r']);
    }
}
