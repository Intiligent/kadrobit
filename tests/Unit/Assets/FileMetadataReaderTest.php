<?php

namespace Tests\Unit\Assets;

use App\Services\Assets\FileMetadataReader;
use Imagick;
use ImagickPixel;
use Tests\TestCase;

class FileMetadataReaderTest extends TestCase
{
    /** @var list<string> */
    protected array $files = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->files);

        parent::tearDown();
    }

    protected function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meta');
        file_put_contents($path, $contents);

        return $this->files[] = $path;
    }

    protected function jpeg(): string
    {
        $image = new Imagick;
        $image->newImage(20, 20, new ImagickPixel('orange'));
        $image->setImageFormat('jpeg');

        return $image->getImageBlob();
    }

    /**
     * @param  array<string, list<string>>  $tags  "2#025" => ["sunset", "sea"]
     */
    protected function jpegWithIptc(array $tags): string
    {
        $data = '';

        foreach ($tags as $tag => $values) {
            foreach ($values as $value) {
                $data .= "\x1C\x02".chr((int) substr($tag, 2)).pack('n', strlen($value)).$value;
            }
        }

        return iptcembed($data, $this->file($this->jpeg()));
    }

    protected function xmp(string $body): string
    {
        return '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/">'.$body.'</rdf:Description></rdf:RDF></x:xmpmeta>';
    }

    public function test_reads_iptc_from_a_jpeg(): void
    {
        $path = $this->file($this->jpegWithIptc([
            '2#005' => ['Sunset over the sea'],
            '2#120' => ['Warm light on gentle waves.'],
            '2#025' => ['Sunset', ' sea ', 'sunset', "Caf\xE9"],
        ]));

        $this->assertSame([
            'title' => 'Sunset over the sea',
            'description' => 'Warm light on gentle waves.',
            // Lowercase, unique, and Latin-1 turned into UTF-8.
            'keywords' => ['sunset', 'sea', 'café'],
            'is_ai_generated' => false,
        ], (new FileMetadataReader)->read($path, 'image/jpeg'));
    }

    public function test_headline_is_the_title_when_there_is_no_object_name(): void
    {
        $path = $this->file($this->jpegWithIptc(['2#105' => ['Old town at dusk']]));

        $this->assertSame('Old town at dusk', (new FileMetadataReader)->read($path, 'image/jpeg')['title']);
    }

    public function test_xmp_is_read_from_any_file_and_wins_over_iptc(): void
    {
        $xmp = $this->xmp(
            '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Pink daisy in a meadow</rdf:li></rdf:Alt></dc:title>'
            .'<dc:description><rdf:Alt><rdf:li xml:lang="x-default">A single flower.</rdf:li></rdf:Alt></dc:description>'
            .'<dc:subject><rdf:Bag><rdf:li>Daisy</rdf:li><rdf:li>meadow</rdf:li></rdf:Bag></dc:subject>'
        );

        $this->assertSame([
            'title' => 'Pink daisy in a meadow',
            'description' => 'A single flower.',
            'keywords' => ['daisy', 'meadow'],
            'is_ai_generated' => false,
        ], (new FileMetadataReader)->read($this->file("\x89PNG...{$xmp}..."), 'image/png'));
    }

    public function test_generative_ai_is_recognized_from_the_digital_source_type(): void
    {
        $xmp = $this->xmp('<Iptc4xmpExt:DigitalSourceType xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
            .'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType>');

        $reader = new FileMetadataReader;

        $this->assertTrue($reader->read($this->file("data {$xmp}"), 'image/png')['is_ai_generated']);
        // A C2PA manifest carries the same URL.
        $this->assertTrue($reader->read($this->file('jumb c2pa ... compositeWithTrainedAlgorithmicMedia ...'), 'image/jpeg')['is_ai_generated']);
    }

    public function test_a_file_without_metadata_gives_nothing(): void
    {
        $this->assertSame(
            ['title' => null, 'description' => null, 'keywords' => [], 'is_ai_generated' => false],
            (new FileMetadataReader)->read($this->file($this->jpeg()), 'image/jpeg'),
        );
    }

    public function test_a_keyword_list_written_as_one_entry_is_split_and_long_keywords_are_cut(): void
    {
        $path = $this->file($this->jpegWithIptc(['2#025' => ['Cat, dog; pet', str_repeat('x', 120)]]));

        $keywords = (new FileMetadataReader)->read($path, 'image/jpeg')['keywords'];

        $this->assertSame(['cat', 'dog', 'pet', str_repeat('x', FileMetadataReader::KEYWORD_MAX)], $keywords);
    }

    public function test_long_texts_and_keyword_lists_are_capped(): void
    {
        $path = $this->file($this->jpegWithIptc([
            '2#005' => [str_repeat('a', 250)],
            '2#025' => array_map(fn ($i) => "word {$i}", range(1, 60)),
        ]));

        $found = (new FileMetadataReader)->read($path, 'image/jpeg');

        $this->assertSame(FileMetadataReader::TEXT_MAX, mb_strlen($found['title']));
        $this->assertCount(FileMetadataReader::KEYWORDS_MAX, $found['keywords']);
    }
}
