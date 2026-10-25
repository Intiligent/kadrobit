<?php

namespace App\Services\Metadata\Drivers;

use App\Services\Metadata\Contracts\MetadataGenerator;
use App\Services\Metadata\GenerationException;
use App\Services\Metadata\GenerationRequest;
use App\Services\Metadata\GenerationResult;
use App\Services\Metadata\MetadataPrompt;
use App\Services\Metadata\MetadataSanitizer;
use App\Services\Metadata\TokenUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * OpenAI Responses API with a strict JSON schema. Plain Http: one endpoint is all it needs, and any
 * OpenAI-compatible gateway is a base_url change.
 */
class OpenAiMetadataGenerator implements MetadataGenerator
{
    /**
     * @param  array{key: ?string, base_url: string, model: string, reasoning_effort: ?string, image_detail: string, max_output_tokens: int, timeout: int}  $config
     */
    public function __construct(
        protected array $config,
        protected MetadataPrompt $prompt,
        protected MetadataSanitizer $sanitizer,
    ) {}

    public function name(): string
    {
        return 'openai:'.$this->config['model'];
    }

    public function generate(GenerationRequest $request): GenerationResult
    {
        if (blank($this->config['key'])) {
            throw new GenerationException('OPENAI_API_KEY is not set.');
        }

        $started = hrtime(true);
        $body = $this->send($this->payload($request));
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $this->assertComplete($body);
        $answer = $this->sanitizer->sanitize($this->decode($this->text($body)), $request);

        return new GenerationResult(
            title: $answer['title'],
            description: $answer['description'],
            keywords: $answer['keywords'],
            categories: $answer['categories'],
            looksEditorial: $answer['looks_editorial'],
            model: $this->config['model'],
            usage: new TokenUsage(
                input: (int) data_get($body, 'usage.input_tokens', 0),
                cachedInput: (int) data_get($body, 'usage.input_tokens_details.cached_tokens', 0),
                output: (int) data_get($body, 'usage.output_tokens', 0),
                reasoning: (int) data_get($body, 'usage.output_tokens_details.reasoning_tokens', 0),
            ),
            durationMs: $durationMs,
            fixes: $answer['fixes'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(GenerationRequest $request): array
    {
        $payload = [
            'model' => $this->config['model'],
            'instructions' => $this->prompt->instructions($request),
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $this->prompt->context($request)],
                    [
                        'type' => 'input_image',
                        'image_url' => 'data:image/jpeg;base64,'.base64_encode($request->image),
                        'detail' => $this->config['image_detail'],
                    ],
                ],
            ]],
            // Reasoning tokens count against this too.
            'max_output_tokens' => $this->config['max_output_tokens'],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'stock_metadata',
                    'strict' => true,
                    'schema' => $this->prompt->schema($request),
                ],
            ],
            // Works are the user's: OpenAI does not keep the request for later retrieval.
            'store' => false,
        ];

        if (filled($this->config['reasoning_effort'])) {
            $payload['reasoning'] = ['effort' => $this->config['reasoning_effort']];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function send(array $payload): array
    {
        try {
            $response = Http::withToken($this->config['key'])
                ->baseUrl($this->config['base_url'])
                ->timeout($this->config['timeout'])
                ->connectTimeout(10)
                ->acceptJson()
                ->asJson()
                ->post('/responses', $payload);
        } catch (ConnectionException $e) {
            throw new GenerationException("OpenAI did not respond within {$this->config['timeout']}s.", previous: $e);
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?: 'HTTP '.$response->status();

            throw new GenerationException("OpenAI request failed ({$this->config['model']}): {$error}");
        }

        return (array) $response->json();
    }

    /**
     * A cut-off answer still comes with HTTP 200.
     *
     * @param  array<string, mixed>  $body
     */
    protected function assertComplete(array $body): void
    {
        if (($body['status'] ?? null) === 'incomplete') {
            $reason = data_get($body, 'incomplete_details.reason', 'unknown');

            throw new GenerationException("OpenAI returned an incomplete answer ({$reason}).");
        }
    }

    /**
     * The answer text from output[].content[]; reasoning items are skipped.
     *
     * @param  array<string, mixed>  $body
     */
    protected function text(array $body): string
    {
        foreach ($body['output'] ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? $item['content'] ?? [] : [] as $block) {
                if (($block['type'] ?? null) === 'refusal') {
                    throw new GenerationException('OpenAI declined: '.($block['refusal'] ?? 'no reason given'));
                }

                if (($block['type'] ?? null) === 'output_text') {
                    return (string) $block['text'];
                }
            }
        }

        throw new GenerationException('OpenAI returned no text.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $text): array
    {
        try {
            return (array) json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GenerationException('OpenAI returned malformed JSON.', previous: $e);
        }
    }
}
