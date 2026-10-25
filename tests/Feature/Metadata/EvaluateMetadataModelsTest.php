<?php

namespace Tests\Feature\Metadata;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class EvaluateMetadataModelsTest extends TestCase
{
    public function test_compares_models_on_folder_images_and_writes_a_report(): void
    {
        config(['metadata.drivers.openai.key' => 'sk-test']);
        Storage::fake('local');
        Http::fake(['api.openai.com/*' => Http::response(OpenAiMetadataGeneratorTest::response(['categories' => []]))]);

        $dir = sys_get_temp_dir().'/metadata-eval-'.uniqid();
        File::ensureDirectoryExists($dir);
        $image = new Imagick;
        $image->newImage(2000, 1000, new ImagickPixel('orange'));
        $image->writeImage("jpeg:{$dir}/sunset.jpg");

        try {
            $this->artisan('metadata:evaluate', ['--dir' => $dir, '--model' => ['gpt-6-luna', 'gpt-5.4-mini'], '--effort' => ''])
                ->expectsOutputToContain('1 images × 2 models')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($dir);
        }

        Http::assertSentCount(2);
        // The image is downscaled to config('metadata.image_size') before it is sent.
        Http::assertSent(function ($request) {
            $blob = base64_decode(substr($request['input'][0]['content'][1]['image_url'], strlen('data:image/jpeg;base64,')));
            $sent = new Imagick;
            $sent->readImageBlob($blob);

            return $sent->getImageWidth() === 1024 && ! isset($request['reasoning']);
        });

        $files = Storage::disk('local')->allFiles('metadata-evaluations');
        $this->assertCount(2, $files);

        $results = json_decode(Storage::disk('local')->get(collect($files)->first(fn ($f) => str_ends_with($f, 'results.json'))), true);
        $this->assertSame('Golden sunset over the ocean', $results['results']['sunset.jpg']['gpt-5.4-mini']['title']);
        $this->assertStringContainsString('gpt-6-luna', Storage::disk('local')->get(collect($files)->first(fn ($f) => str_ends_with($f, 'report.html'))));
    }
}
