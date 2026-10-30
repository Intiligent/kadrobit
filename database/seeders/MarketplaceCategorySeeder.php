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
 * - Dreamstime: the "Image Legend" sheet of its CSV template (docs/marketplaces/dreamstime/categories.csv).
 *   The CSV "Category 1–3" columns take the category number; the video template has the same list.
 * - Alamy: "Alamy Content Categorisation" (Image Categories Guide, linked from alamy.com/help/contributor-image-sales-guide,
 *   checked 30.10.2026). One list of 25 for the Primary and the optional Secondary category, set by name in Image Manager:
 *   external_id = name. Alamy takes no video from contributors.
 */
class MarketplaceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdobeStock();
        $this->seedShutterstock();
        $this->seedDreamstime();
        $this->seedAlamy();
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

    private function seedDreamstime(): void
    {
        // Section → subsection, in the order of Dreamstime's legend.
        $names = [
            211 => 'Abstract → Aerial',
            112 => 'Abstract → Backgrounds',
            39 => 'Abstract → Blurs',
            164 => 'Abstract → Colors',
            40 => 'Abstract → Competition',
            41 => 'Abstract → Craftsmanship',
            42 => 'Abstract → Danger',
            43 => 'Abstract → Exploration',
            158 => 'Abstract → Fun',
            44 => 'Abstract → Help',
            149 => 'Abstract → Love',
            45 => 'Abstract → Luxury',
            187 => 'Abstract → Mobile',
            46 => 'Abstract → Peace',
            165 => 'Abstract → Planetarium',
            47 => 'Abstract → Power',
            48 => 'Abstract → Purity',
            128 => 'Abstract → Religion',
            155 => 'Abstract → Seasonal & Holiday',
            49 => 'Abstract → Security',
            50 => 'Abstract → Sports',
            51 => 'Abstract → Stress',
            52 => 'Abstract → Success',
            53 => 'Abstract → Teamwork',
            141 => 'Abstract → Textures',
            54 => 'Abstract → Unique',
            31 => 'Animals → Birds',
            33 => 'Animals → Farm',
            36 => 'Animals → Insects',
            32 => 'Animals → Mammals',
            34 => 'Animals → Marine life',
            30 => 'Animals → Pets',
            35 => 'Animals → Reptiles & Amphibians',
            37 => 'Animals → Rodents',
            168 => 'Animals → Wildlife',
            124 => 'Arts & Architecture → Details',
            71 => 'Arts & Architecture → Generic architecture',
            132 => 'Arts & Architecture → Historic buildings',
            153 => 'Arts & Architecture → Home',
            73 => 'Arts & Architecture → Indoor',
            70 => 'Arts & Architecture → Landmarks',
            131 => 'Arts & Architecture → Modern buildings',
            130 => 'Arts & Architecture → Night scenes',
            72 => 'Arts & Architecture → Outdoor',
            174 => 'Arts & Architecture → Ruins & Ancient',
            154 => 'Arts & Architecture → Work places',
            79 => 'Business → Communications',
            78 => 'Business → Computers',
            80 => 'Business → Finance',
            77 => 'Business → Industries',
            83 => 'Business → Metaphors',
            84 => 'Business → Objects',
            75 => 'Business → People',
            81 => 'Business → Still-life',
            76 => 'Business → Teams',
            82 => 'Business → Transportation',
            85 => 'Business → Travel',
            178 => 'Editorial → Celebrities',
            185 => 'Editorial → Commercial',
            179 => 'Editorial → Events',
            184 => 'Editorial → Landmarks',
            180 => 'Editorial → People',
            181 => 'Editorial → Politics',
            182 => 'Editorial → Sports',
            183 => 'Editorial → Weather & Environment',
            204 => 'Holidays → Chinese New Year',
            190 => 'Holidays → Christmas',
            207 => 'Holidays → Cinco de Mayo',
            203 => 'Holidays → Diwali',
            193 => 'Holidays → Easter',
            196 => 'Holidays → Fathers Day',
            192 => 'Holidays → Halloween',
            208 => 'Holidays → Hanukkah',
            206 => 'Holidays → Mardi Gras',
            195 => 'Holidays → Mothers Day',
            189 => 'Holidays → New Years',
            202 => 'Holidays → Other',
            205 => 'Holidays → Ramadan',
            191 => 'Holidays → Thanksgiving',
            194 => 'Holidays → Valentines Day',
            210 => 'IT & C → Artificial Intelligence',
            110 => 'IT & C → Connectivity',
            113 => 'IT & C → Equipment',
            111 => 'IT & C → Internet',
            109 => 'IT & C → Networking',
            212 => 'Illustrations & Clipart → AI generated',
            166 => 'Illustrations & Clipart → 3D & Computer generated',
            167 => 'Illustrations & Clipart → Hand drawn & Artistic',
            163 => 'Illustrations & Clipart → Illustrations',
            186 => 'Illustrations & Clipart → Vector',
            101 => 'Industries → Agriculture',
            89 => 'Industries → Architecture',
            87 => 'Industries → Banking',
            93 => 'Industries → Cargo & Shipping',
            94 => 'Industries → Communications',
            91 => 'Industries → Computers',
            90 => 'Industries → Construction',
            150 => 'Industries → Education',
            136 => 'Industries → Entertainment',
            99 => 'Industries → Environment',
            127 => 'Industries → Food & Beverages',
            92 => 'Industries → Healthcare & Medical',
            96 => 'Industries → Insurance',
            95 => 'Industries → Legal',
            100 => 'Industries → Manufacturing',
            102 => 'Industries → Military',
            161 => 'Industries → Oil and gas',
            97 => 'Industries → Power and energy',
            157 => 'Industries → Sports',
            98 => 'Industries → Transportation',
            88 => 'Industries → Travel',
            22 => 'Nature → Clouds and skies',
            17 => 'Nature → Deserts',
            14 => 'Nature → Details',
            27 => 'Nature → Fields & Meadows',
            25 => 'Nature → Flowers & Gardens',
            28 => 'Nature → Food ingredients',
            18 => 'Nature → Forests',
            137 => 'Nature → Fruits & Vegetables',
            11 => 'Nature → Generic vegetation',
            143 => 'Nature → Geologic and mineral',
            16 => 'Nature → Lakes and rivers',
            146 => 'Nature → Landscapes',
            15 => 'Nature → Mountains',
            12 => 'Nature → Plants and trees',
            19 => 'Nature → Sea & Ocean',
            26 => 'Nature → Seasons specific',
            23 => 'Nature → Sunsets & Sunrises',
            20 => 'Nature → Tropical',
            171 => 'Nature → Water',
            24 => 'Nature → Waterfalls',
            142 => 'Objects → Clothing & Accessories',
            147 => 'Objects → Electronics',
            138 => 'Objects → Home related',
            135 => 'Objects → Isolated',
            151 => 'Objects → Music and sound',
            145 => 'Objects → Other',
            152 => 'Objects → Retro',
            156 => 'Objects → Sports',
            144 => 'Objects → Still life',
            140 => 'Objects → Tools',
            134 => 'Objects → Toys',
            123 => 'People → Active',
            139 => 'People → Body parts',
            119 => 'People → Children',
            175 => 'People → Cosmetic & Makeup',
            115 => 'People → Couples',
            122 => 'People → Diversity',
            159 => 'People → Expressions',
            118 => 'People → Families',
            117 => 'People → Men',
            173 => 'People → Nudes',
            162 => 'People → Portraits',
            121 => 'People → Seniors',
            120 => 'People → Teens',
            116 => 'People → Women',
            160 => 'People → Workers',
            105 => 'Technology → Computers',
            106 => 'Technology → Connections',
            129 => 'Technology → Electronics',
            148 => 'Technology → Other',
            107 => 'Technology → Retro',
            209 => 'Technology → Science',
            104 => 'Technology → Telecommunications',
            56 => 'Travel → Africa',
            58 => 'Travel → America',
            176 => 'Travel → Antarctica',
            65 => 'Travel → Arts & Architecture',
            57 => 'Travel → Asia',
            60 => 'Travel → Australasian',
            62 => 'Travel → Cruise',
            63 => 'Travel → Cuisine',
            67 => 'Travel → Currencies',
            61 => 'Travel → Destination scenics',
            59 => 'Travel → Europe',
            68 => 'Travel → Flags',
            64 => 'Travel → Resorts',
            66 => 'Travel → Tropical',
            201 => 'Web Design Graphics → Banners',
            200 => 'Web Design Graphics → Buttons',
            199 => 'Web Design Graphics → Web Backgrounds & Textures',
            198 => 'Web Design Graphics → Web Icons',
        ];

        $categories = [];

        foreach ($names as $number => $name) {
            $categories[] = [
                'external_id' => (string) $number,
                'name' => $name,
                'media_types' => MediaType::cases(),
            ];
        }

        $this->seed('dreamstime', $categories);
    }

    private function seedAlamy(): void
    {
        $names = [
            'Abstracts and backgrounds',
            'Animals and wildlife',
            'Architecture and interiors',
            'Archive or historical',
            'Art and artwork',
            'Business',
            'Celebrations and life events',
            'Concepts',
            'Education',
            'Entertainment and celebrities',
            'Food and drink',
            'Healthcare and medical',
            'Industry and agriculture',
            'Landscapes',
            'Lifestyle',
            'News and reportage',
            'Objects and still life',
            'Occupations',
            'Plants and gardens',
            'Science',
            'Sport',
            'Technology',
            'Transport',
            'Travel',
            'Weather and seasons',
        ];

        $categories = [];

        foreach ($names as $name) {
            $categories[] = [
                'external_id' => $name,
                'name' => $name,
                'media_types' => [MediaType::Photo, MediaType::Illustration, MediaType::Vector],
            ];
        }

        $this->seed('alamy', $categories);
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
