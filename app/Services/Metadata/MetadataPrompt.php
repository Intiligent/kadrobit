<?php

namespace App\Services\Metadata;

/**
 * The provider-neutral part of a generation: instructions, the per-work context and the JSON schema
 * of the answer. Every driver sends the same, so models can be compared and swapped.
 *
 * The instructions come first and change only with the category lists, so providers can cache them.
 */
class MetadataPrompt
{
    public function instructions(GenerationRequest $request): string
    {
        $limits = config('metadata.limits');

        $text = <<<TEXT
            You write metadata for stock marketplaces (Adobe Stock, Shutterstock, Pond5, Alamy and others) from one image.
            Buyers find works by these texts, and reviewers reject misleading or spammy ones. Write in English.

            title: what the image shows, in at most {$limits['title']} characters. The main subject, its action and the setting,
            as a natural phrase, not a list. No commas, no trailing period, no "image of" or "photo of".

            description: one factual sentence of at most {$limits['description']} characters that adds to the title rather than
            repeats it: who or what, doing what, where, the mood or use. Normal punctuation: the no-commas rule is for the
            title only. Do not stuff keywords into it.

            keywords: {$limits['keywords']} keywords, most important first. The first 10 decide search ranking: the main subject,
            action, setting and the concepts buyers search for. Then secondary objects, concepts, mood, colors, composition and
            technical terms (for example "copy space", "nobody", "top view"). Lowercase, one to three words each, singular,
            no duplicates or near-duplicates: not both "daisy" and "daisies".

            looks_editorial: true when the image shows visible brands or logos, recognizable people or a news event:
            such works usually can be sold only as editorial.

            Never name brands, trademarks, real people or artists anywhere. Do not invent places, dates or events: use a place
            or date only when it is given below. If the filename looks like a camera name (IMG_1234), ignore it.
            TEXT;

        if ($request->categories) {
            $text .= "\n\ncategories: for each marketplace pick the best fitting category IDs from its list, at most the number given.";

            foreach ($request->categories as $code => $list) {
                $max = $request->categoriesMax[$code] ?? 1;
                $text .= "\n\n{$code} (at most {$max}):";

                foreach ($list as $id => $name) {
                    $text .= "\n{$id}: {$name}";
                }
            }
        }

        return $text;
    }

    /**
     * What is known about this work besides the image.
     */
    public function context(GenerationRequest $request): string
    {
        $lines = ["Work type: {$request->type->value}."];

        if ($request->filename) {
            $lines[] = "Filename: {$request->filename}.";
        }

        if ($request->capturedAt) {
            $lines[] = 'Taken on: '.$request->capturedAt->toDateString().'.';
        }

        $place = collect([$request->location['city'] ?? null, $request->location['country'] ?? null])->filter()->implode(', ');

        if ($place !== '') {
            $lines[] = "Place: {$place}.";
        }

        return implode("\n", $lines);
    }

    /**
     * JSON schema of the answer in the strict subset (every property required, no extra ones), which OpenAI,
     * Anthropic and Gemini all accept. Lengths and counts are not enforceable in it: MetadataSanitizer checks them.
     *
     * @return array<string, mixed>
     */
    public function schema(GenerationRequest $request): array
    {
        $properties = [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
            'looks_editorial' => ['type' => 'boolean'],
        ];

        if ($request->categories) {
            $categories = [];

            foreach ($request->categories as $code => $list) {
                $categories[$code] = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_map('strval', array_keys($list))]];
            }

            $properties['categories'] = [
                'type' => 'object',
                'properties' => $categories,
                'required' => array_keys($categories),
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
