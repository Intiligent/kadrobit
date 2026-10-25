<?php

namespace Tests\Unit\Metadata;

use App\Enums\MediaType;
use App\Services\Metadata\GenerationRequest;
use App\Services\Metadata\MetadataPrompt;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MetadataPromptTest extends TestCase
{
    public function test_schema_is_strict_and_lists_categories_as_enums(): void
    {
        $schema = (new MetadataPrompt)->schema(new GenerationRequest('jpeg', MediaType::Photo, categories: [
            'adobe_stock' => ['11' => 'Landscapes', '14' => 'Plants and Flowers'],
        ]));

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['title', 'description', 'keywords', 'looks_editorial', 'categories'], $schema['required']);
        $this->assertSame(['11', '14'], $schema['properties']['categories']['properties']['adobe_stock']['items']['enum']);
        $this->assertSame(['adobe_stock'], $schema['properties']['categories']['required']);
    }

    public function test_no_categories_field_without_lists(): void
    {
        $prompt = new MetadataPrompt;
        $request = new GenerationRequest('jpeg', MediaType::Photo);

        $this->assertArrayNotHasKey('categories', $prompt->schema($request)['properties']);
        $this->assertStringNotContainsString('categories:', $prompt->instructions($request));
    }

    public function test_instructions_carry_limits_and_category_lists(): void
    {
        $text = (new MetadataPrompt)->instructions(new GenerationRequest('jpeg', MediaType::Photo, categories: [
            'shutterstock' => ['Nature' => 'Nature'],
        ], categoriesMax: ['shutterstock' => 2]));

        $this->assertStringContainsString('at most 70 characters', $text);
        $this->assertStringContainsString('at most 150 characters', $text);
        $this->assertStringContainsString("shutterstock (at most 2):\nNature: Nature", $text);
    }

    public function test_context_gives_only_what_is_known(): void
    {
        $prompt = new MetadataPrompt;

        $this->assertSame('Work type: vector.', $prompt->context(new GenerationRequest('jpeg', MediaType::Vector)));
        $this->assertSame(
            "Work type: photo.\nFilename: lisbon.jpg.\nTaken on: 2026-06-01.\nPlace: Lisbon, Portugal.",
            $prompt->context(new GenerationRequest('jpeg', MediaType::Photo, 'lisbon.jpg', Carbon::parse('2026-06-01 10:00'), ['city' => 'Lisbon', 'country' => 'Portugal'])),
        );
    }
}
