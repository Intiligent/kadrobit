<?php

namespace Tests\Unit\Metadata;

use App\Enums\MediaType;
use App\Services\Metadata\GenerationRequest;
use App\Services\Metadata\MetadataSanitizer;
use Tests\TestCase;

class MetadataSanitizerTest extends TestCase
{
    protected function request(): GenerationRequest
    {
        return new GenerationRequest('jpeg', MediaType::Photo, categories: [
            'adobe_stock' => ['11' => 'Landscapes', '14' => 'Plants and Flowers'],
            'shutterstock' => ['Nature' => 'Nature', 'Objects' => 'Objects', 'Parks/Outdoor' => 'Parks/Outdoor'],
        ], categoriesMax: ['adobe_stock' => 1, 'shutterstock' => 2]);
    }

    public function test_a_clean_answer_passes_unchanged(): void
    {
        $answer = (new MetadataSanitizer)->sanitize([
            'title' => 'Pink daisy blooming in a meadow',
            'description' => 'A single pink flower stands above green grass.',
            'keywords' => ['daisy', 'meadow'],
            'categories' => ['adobe_stock' => ['14'], 'shutterstock' => ['Nature']],
            'looks_editorial' => false,
        ], $this->request());

        $this->assertSame('Pink daisy blooming in a meadow', $answer['title']);
        $this->assertSame(['adobe_stock' => ['14'], 'shutterstock' => ['Nature']], $answer['categories']);
        $this->assertSame([], $answer['fixes']);
    }

    public function test_title_loses_commas_and_the_trailing_period_and_fits_the_limit(): void
    {
        $answer = (new MetadataSanitizer)->sanitize([
            'title' => 'Hydrangeas, straw hat and coffee on a seaside cafe table overlooking the calm blue water.',
        ], $this->request());

        $this->assertStringNotContainsString(',', $answer['title']);
        $this->assertLessThanOrEqual(70, mb_strlen($answer['title']));
        $this->assertStringEndsNotWith(' ', $answer['title']);
        $this->assertSame('Hydrangeas straw hat and coffee on a seaside cafe table overlooking', $answer['title']);
        $this->assertSame(['title_commas', 'title_too_long'], $answer['fixes']);
    }

    public function test_description_is_cut_at_a_word(): void
    {
        $answer = (new MetadataSanitizer)->sanitize(['description' => str_repeat('golden light ', 20)], $this->request());

        $this->assertLessThanOrEqual(150, mb_strlen($answer['description']));
        // Ends on a whole word: every word of the text is "golden" or "light".
        $this->assertContains(last(explode(' ', $answer['description'])), ['golden', 'light']);
        $this->assertContains('description_too_long', $answer['fixes']);
    }

    public function test_keywords_are_lowercased_deduplicated_with_plurals_and_capped(): void
    {
        $answer = (new MetadataSanitizer)->sanitize([
            'keywords' => ['Daisy', ' daisies ', 'Meadow', 'daisy', '', ...array_map(fn ($i) => "word {$i}", range(1, 60))],
        ], $this->request());

        $this->assertSame(['daisy', 'meadow', 'word 1'], array_slice($answer['keywords'], 0, 3));
        $this->assertCount(49, $answer['keywords']);
        $this->assertContains('keyword_duplicates', $answer['fixes']);
        $this->assertContains('keywords_too_many', $answer['fixes']);
    }

    public function test_categories_outside_the_list_or_over_the_limit_are_dropped(): void
    {
        $answer = (new MetadataSanitizer)->sanitize([
            'categories' => ['adobe_stock' => ['14', '11'], 'shutterstock' => ['Nature', 'Food', 'Objects', 'Parks/Outdoor'], 'pond5' => ['x']],
        ], $this->request());

        $this->assertSame(['adobe_stock' => ['14'], 'shutterstock' => ['Nature', 'Objects']], $answer['categories']);
        $this->assertContains('category_invalid', $answer['fixes']);
        $this->assertContains('categories_too_many', $answer['fixes']);
    }
}
