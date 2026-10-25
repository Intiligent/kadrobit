<?php

namespace Tests\Unit\Marketplaces;

use App\Services\Marketplaces\Delivery\JpegMetadata;
use Tests\TestCase;

class JpegMetadataTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $image = imagecreatetruecolor(40, 30);
        $this->path = tempnam(sys_get_temp_dir(), 'kb-jpeg-');
        imagejpeg($image, $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /** @return array<string, list<string>> */
    protected function iptc(): array
    {
        getimagesize($this->path, $info);

        return iptcparse($info['APP13']);
    }

    public function test_it_writes_iptc_in_utf8(): void
    {
        (new JpegMetadata)->embed($this->path, 'Glacier lagoon', 'Icebergs at sunrise near Jökulsárlón', ['glacier', 'iceberg', 'café']);

        $iptc = $this->iptc();

        $this->assertSame("\x1B%G", $iptc['1#090'][0]);
        $this->assertSame('Glacier lagoon', $iptc['2#005'][0]);
        $this->assertSame('Glacier lagoon', $iptc['2#105'][0]);
        $this->assertSame('Icebergs at sunrise near Jökulsárlón', $iptc['2#120'][0]);
        $this->assertSame(['glacier', 'iceberg', 'café'], $iptc['2#025']);
    }

    public function test_it_writes_xmp_and_keeps_the_image_readable(): void
    {
        (new JpegMetadata)->embed($this->path, 'Fish & chips', 'A plate of "fish" & chips', ['fish', 'chips']);

        $jpeg = file_get_contents($this->path);

        $this->assertStringContainsString('<dc:description><rdf:Alt><rdf:li xml:lang="x-default">A plate of &quot;fish&quot; &amp; chips</rdf:li></rdf:Alt></dc:description>', $jpeg);
        $this->assertStringContainsString('<dc:subject><rdf:Bag><rdf:li>fish</rdf:li><rdf:li>chips</rdf:li></rdf:Bag></dc:subject>', $jpeg);
        $this->assertStringContainsString('<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Fish &amp; chips</rdf:li></rdf:Alt></dc:title>', $jpeg);
        $this->assertSame([40, 30], array_slice(getimagesize($this->path), 0, 2));
        $this->assertNotFalse(imagecreatefromjpeg($this->path));
    }

    public function test_it_replaces_the_files_own_metadata(): void
    {
        $writer = new JpegMetadata;
        $writer->embed($this->path, 'Old title', 'Old caption from Lightroom', ['old']);
        $writer->embed($this->path, 'New title', 'New caption', ['new']);

        $jpeg = file_get_contents($this->path);

        $this->assertSame(1, substr_count($jpeg, '<?xpacket begin'));
        $this->assertStringNotContainsString('Old caption', $jpeg);
        $this->assertSame(['New caption'], $this->iptc()['2#120']);
        $this->assertSame(['new'], $this->iptc()['2#025']);
    }

    public function test_it_cuts_texts_to_the_iptc_limits_without_breaking_a_character(): void
    {
        (new JpegMetadata)->embed($this->path, str_repeat('ж', 40), 'Caption', [str_repeat('é', 40)]);

        $iptc = $this->iptc();

        $this->assertSame(str_repeat('ж', 32), $iptc['2#005'][0]);
        $this->assertSame(str_repeat('ж', 40), $iptc['2#105'][0]);
        $this->assertSame(str_repeat('é', 32), $iptc['2#025'][0]);
    }
}
