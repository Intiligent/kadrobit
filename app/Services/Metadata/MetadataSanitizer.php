<?php

namespace App\Services\Metadata;

use Illuminate\Support\Str;

/**
 * Brings a model's answer within the limits whatever the provider: schemas cannot enforce lengths and counts.
 * Every correction is recorded, so the evaluation shows how well a model follows the rules.
 */
class MetadataSanitizer
{
    /**
     * @param  array<string, mixed>  $answer  Decoded JSON of the answer
     * @return array{title: string, description: string, keywords: list<string>, categories: array<string, list<string>>, looks_editorial: bool, fixes: list<string>}
     */
    public function sanitize(array $answer, GenerationRequest $request): array
    {
        $limits = config('metadata.limits');
        $fixes = [];

        $title = $this->squish((string) ($answer['title'] ?? ''));

        if (str_contains($title, ',')) {
            $fixes[] = 'title_commas';
            $title = $this->squish(str_replace(',', ' ', $title));
        }

        $title = rtrim($title, '.');
        $title = $this->cut($title, $limits['title'], 'title_too_long', $fixes);

        $description = $this->cut($this->squish((string) ($answer['description'] ?? '')), $limits['description'], 'description_too_long', $fixes);

        $keywords = [];
        $seen = [];

        foreach ((array) ($answer['keywords'] ?? []) as $keyword) {
            $keyword = Str::lower($this->squish((string) $keyword));

            if ($keyword === '') {
                continue;
            }

            // "daisies" after "daisy" is the same keyword to a buyer, and marketplaces count it as spam.
            $key = Str::singular($keyword);

            if (isset($seen[$key])) {
                $fixes[] = 'keyword_duplicates';

                continue;
            }

            $seen[$key] = true;
            $keywords[] = $keyword;
        }

        if (count($keywords) > $limits['keywords']) {
            $fixes[] = 'keywords_too_many';
            $keywords = array_slice($keywords, 0, $limits['keywords']);
        }

        $categories = [];

        foreach ($request->categories as $code => $list) {
            $picked = array_values(array_unique(array_map('strval', (array) ($answer['categories'][$code] ?? []))));
            $valid = array_values(array_filter($picked, fn (string $id) => array_key_exists($id, $list)));

            if (count($valid) < count($picked)) {
                $fixes[] = 'category_invalid';
            }

            $max = $request->categoriesMax[$code] ?? 1;

            if (count($valid) > $max) {
                $fixes[] = 'categories_too_many';
                $valid = array_slice($valid, 0, $max);
            }

            if ($valid) {
                $categories[$code] = $valid;
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'categories' => $categories,
            'looks_editorial' => (bool) ($answer['looks_editorial'] ?? false),
            'fixes' => array_values(array_unique($fixes)),
        ];
    }

    protected function squish(string $text): string
    {
        return Str::squish($text);
    }

    /**
     * Cuts at a word boundary, so a too long text never ends mid-word.
     *
     * @param  list<string>  $fixes
     */
    protected function cut(string $text, int $max, string $fix, array &$fixes): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $fixes[] = $fix;
        $cut = mb_substr($text, 0, $max + 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space ? mb_substr($cut, 0, $space) : mb_substr($text, 0, $max), ' ,;:-');
    }
}
