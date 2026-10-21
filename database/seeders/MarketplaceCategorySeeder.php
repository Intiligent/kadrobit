<?php

namespace Database\Seeders;

use App\Enums\MediaType;
use App\Models\Marketplace;
use Illuminate\Database\Seeder;

/**
 * Category lists of the marketplaces, as used in their CSV metadata files.
 *
 * Sources (checked 21.10.2026):
 * - Adobe Stock: https://helpx.adobe.com/stock/contributor/content-policies-guidelines/metadata/choose-right-category-content.html
 *   The CSV "Category" column takes the category number. Adobe's page lists the 21 categories
 *   in order but without numbers; numbering 1–21 by that order follows the Adobe Community answer
 *   https://community.adobe.com/questions-38/what-are-the-category-numbers-for-csv-329049
 *   Adobe does not separate categories by content type.
 * - Shutterstock: https://submit.shutterstock.com/help/en/articles/10617427-content-publishing-standards-contextual-metadata
 *   The CSV "Categories" column takes category names (1 required, 2nd optional), so external_id = name.
 *   One list for all content; categories marked * are "Not available for video".
 */
class MarketplaceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdobeStock();
        $this->seedShutterstock();
    }

    private function seedAdobeStock(): void
    {
        $names = [
            1 => 'Animals',
            2 => 'Buildings and Architecture',
            3 => 'Business',
            4 => 'Drinks',
            5 => 'The Environment',
            6 => 'States of Mind',
            7 => 'Food',
            8 => 'Graphic Resources',
            9 => 'Hobbies and Leisure',
            10 => 'Industry',
            11 => 'Landscapes',
            12 => 'Lifestyle',
            13 => 'People',
            14 => 'Plants and Flowers',
            15 => 'Culture and Religion',
            16 => 'Science',
            17 => 'Social Issues',
            18 => 'Sports',
            19 => 'Technology',
            20 => 'Transport',
            21 => 'Travel',
        ];

        $categories = [];

        foreach ($names as $number => $name) {
            $categories[] = [
                'external_id' => (string) $number,
                'name' => $name,
                'media_types' => MediaType::cases(),
            ];
        }

        $this->seed('adobe_stock', $categories);
    }

    private function seedShutterstock(): void
    {
        $names = [
            'Abstract',
            'Animals/Wildlife',
            'Arts',
            'Backgrounds/Textures',
            'Beauty/Fashion',
            'Buildings/Landmarks',
            'Business/Finance',
            'Celebrities',
            'Education',
            'Food and Drink',
            'Healthcare/Medical',
            'Holidays',
            'Industrial',
            'Interiors',
            'Miscellaneous',
            'Nature',
            'Objects',
            'Parks/Outdoor',
            'People',
            'Religion',
            'Science',
            'Signs/Symbols',
            'Sports/Recreation',
            'Technology',
            'Transportation',
            'Vintage',
        ];

        // Marked "Not available for video" in Shutterstock's list.
        $notForVideo = [
            'Abstract',
            'Beauty/Fashion',
            'Celebrities',
            'Interiors',
            'Miscellaneous',
            'Parks/Outdoor',
            'Vintage',
        ];

        $categories = [];

        foreach ($names as $name) {
            $mediaTypes = in_array($name, $notForVideo, true)
                ? [MediaType::Photo, MediaType::Vector, MediaType::Illustration]
                : MediaType::cases();

            $categories[] = [
                'external_id' => $name,
                'name' => $name,
                'media_types' => $mediaTypes,
            ];
        }

        $this->seed('shutterstock', $categories);
    }

    private function seed(string $marketplaceCode, array $categories): void
    {
        $marketplace = Marketplace::where('code', $marketplaceCode)->firstOrFail();

        foreach ($categories as $category) {
            $marketplace->categories()->updateOrCreate(
                ['external_id' => $category['external_id']],
                $category,
            );
        }
    }
}
