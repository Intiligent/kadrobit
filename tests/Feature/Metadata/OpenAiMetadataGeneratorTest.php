<?php

namespace Tests\Feature\Metadata;

use App\Enums\MediaType;
use App\Services\Metadata\Contracts\MetadataGenerator;
use App\Services\Metadata\Drivers\OpenAiMetadataGenerator;
use App\Services\Metadata\GenerationException;
use App\Services\Metadata\GenerationRequest;
use App\Services\Metadata\MetadataGeneratorFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class OpenAiMetadataGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'metadata.driver' => 'openai',
            'metadata.drivers.openai.key' => 'sk-test',
            'metadata.drivers.openai.model' => 'gpt-6-luna',
            'metadata.drivers.openai.reasoning_effort' => 'low',
        ]);
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    public static function response(array $answer = [], array $body = []): array
    {
        return [
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'title' => 'Golden sunset over the ocean',
                    'description' => 'Warm light glimmers on gentle waves.',
                    'keywords' => ['sunset', 'ocean', 'Sunsets'],
                    'looks_editorial' => false,
                    'categories' => ['adobe_stock' => ['11']],
                    ...$answer,
                ])]]],
            ],
            'usage' => [
                'input_tokens' => 1800,
                'input_tokens_details' => ['cached_tokens' => 1000],
                'output_tokens' => 400,
                'output_tokens_details' => ['reasoning_tokens' => 120],
            ],
            ...$body,
        ];
    }

    protected function request(): GenerationRequest
    {
        return new GenerationRequest('jpeg-bytes', MediaType::Photo, 'IMG_1.jpg', categories: ['adobe_stock' => ['11' => 'Landscapes']]);
    }

    public function test_sends_image_instructions_and_strict_schema(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(self::response())]);

        app(MetadataGenerator::class)->generate($this->request());

        Http::assertSent(function (Request $request) {
            $content = $request['input'][0]['content'];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer sk-test')
                && $request['model'] === 'gpt-6-luna'
                && $request['reasoning'] === ['effort' => 'low']
                && $request['store'] === false
                && str_contains($request['instructions'], 'adobe_stock (at most 1)')
                && $content[0]['text'] === "Work type: photo.\nFilename: IMG_1.jpg."
                && $content[1]['image_url'] === 'data:image/jpeg;base64,'.base64_encode('jpeg-bytes')
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['properties']['categories']['properties']['adobe_stock']['items']['enum'] === ['11'];
        });
    }

    public function test_returns_the_sanitized_answer_with_usage_and_cost(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(self::response())]);

        $result = app(MetadataGenerator::class)->generate($this->request());

        $this->assertSame('Golden sunset over the ocean', $result->title);
        $this->assertSame(['sunset', 'ocean'], $result->keywords);
        $this->assertSame(['adobe_stock' => ['11']], $result->categories);
        $this->assertSame(['keyword_duplicates'], $result->fixes);
        $this->assertSame(1800, $result->usage->input);
        $this->assertSame(120, $result->usage->reasoning);
        // (800 × 0.10 + 1000 × 0.01 + 400 × 0.50) / 1M
        $this->assertEqualsWithDelta(0.00029, $result->cost(), 1e-9);
        $this->assertSame('openai:gpt-6-luna', app(MetadataGenerator::class)->name());
    }

    public function test_reasoning_is_not_sent_when_turned_off(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(self::response())]);

        app(MetadataGeneratorFactory::class)->make('openai', ['reasoning_effort' => ''])->generate($this->request());

        Http::assertSent(fn (Request $request) => ! isset($request['reasoning']));
    }

    public function test_failures_become_generation_exceptions(): void
    {
        $cases = [
            [Http::response(['error' => ['message' => 'Invalid model']], 400), 'Invalid model'],
            [Http::response(self::response(body: ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']])), 'incomplete answer (max_output_tokens)'],
            [Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Not allowed']]]]]), 'declined: Not allowed'],
            [Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{oops']]]]]), 'malformed JSON'],
        ];

        Http::fake(['api.openai.com/*' => Http::sequence(array_column($cases, 0))]);

        foreach (array_column($cases, 1) as $message) {
            try {
                app(MetadataGenerator::class)->generate($this->request());
                $this->fail("No exception for: {$message}");
            } catch (GenerationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertStringNotContainsString('sk-test', $e->getMessage());
            }
        }
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        Http::fake();
        config(['metadata.drivers.openai.key' => null]);

        $this->expectExceptionObject(new GenerationException('OPENAI_API_KEY is not set.'));

        try {
            app(MetadataGenerator::class)->generate($this->request());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_driver_comes_from_config(): void
    {
        $this->assertInstanceOf(OpenAiMetadataGenerator::class, app(MetadataGenerator::class));
        $this->assertSame('openai:gpt-5.4-mini', app(MetadataGeneratorFactory::class)->make(overrides: ['model' => 'gpt-5.4-mini'])->name());

        config(['metadata.driver' => 'other']);

        $this->expectException(InvalidArgumentException::class);
        app(MetadataGenerator::class);
    }
}
