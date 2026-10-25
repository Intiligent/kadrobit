<?php

namespace Tests\Unit\Marketplaces;

use App\Models\Asset;
use App\Models\AssetListing;
use App\Services\Marketplaces\Delivery\RemoteFilename;
use App\Services\Marketplaces\ShutterstockCsv;
use Tests\TestCase;

class ShutterstockCsvTest extends TestCase
{
    protected function listing(string $filename, array $asset): AssetListing
    {
        return (new AssetListing(['remote_filename' => $filename]))->setRelation('asset', new Asset($asset));
    }

    public function test_it_lists_sent_files_with_the_works_metadata(): void
    {
        $csv = (new ShutterstockCsv)->build([
            $this->listing('lagoon.jpg', [
                'type' => 'photo',
                'title' => 'Glacier lagoon',
                'description' => 'Icebergs, at "sunrise"',
                'keywords' => ['glacier', 'iceberg'],
                'categories' => ['shutterstock' => ['Nature', 'Parks/Outdoor']],
                'is_editorial' => false,
            ]),
            $this->listing('logo.eps', [
                'type' => 'vector',
                'title' => 'Logo',
                'description' => '',
                'keywords' => ['logo'],
                'categories' => [],
                'is_editorial' => true,
            ]),
        ]);

        $this->assertSame([
            'Filename,Description,Keywords,Categories,Illustration,"Mature Content",Editorial',
            'lagoon.jpg,"Icebergs, at ""sunrise""","glacier,iceberg","Nature,Parks/Outdoor",no,no,no',
            'logo.eps,Logo,logo,,yes,no,yes',
        ], explode("\n", trim($csv)));
    }

    public function test_remote_names_are_safe_for_ftp(): void
    {
        $this->assertSame('untitled (100000021 of 58).jpg', RemoteFilename::safe('untitled (100000021 of 58).JPG', 'x'));
        $this->assertSame('Zakat na more.jpg', RemoteFilename::safe('Закат на море.jpg', 'x'));
        $this->assertSame('a-b-c.png', RemoteFilename::safe('a:b*c?.png', 'x'));
        $this->assertSame('01abc.jpg', RemoteFilename::safe('???.jpg', '01ABC'));
        $this->assertSame('logo.jpg', RemoteFilename::withExtension('logo.eps', 'jpg'));
    }
}
