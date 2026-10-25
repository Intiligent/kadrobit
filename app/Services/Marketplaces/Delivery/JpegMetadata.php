<?php

namespace App\Services\Marketplaces\Delivery;

use RuntimeException;

/**
 * Writes the title, description and keywords into a JPEG copy for a marketplace, both as IPTC (APP13) and as
 * XMP (APP1): marketplaces read one or the other, and a stale XMP from Lightroom must not win over our IPTC.
 *
 * - IPTC: Object Name and Headline (title), Caption-Abstract (description), Keywords; UTF-8, declared in the
 *   envelope (1:90). Written by iptcembed(), which replaces the file's own APP13.
 * - XMP: dc:title, dc:description, dc:subject and photoshop:Headline. The file's own XMP packet is replaced
 *   as a whole, other XMP properties of the copy included; the original in storage is never changed.
 */
class JpegMetadata
{
    protected const XMP_HEADER = "http://ns.adobe.com/xap/1.0/\x00";

    protected const XMP_EXTENSION_HEADER = "http://ns.adobe.com/xmp/extension/\x00";

    /** IPTC length limits (IIM 4.2), in bytes. */
    protected const OBJECT_NAME_MAX = 64;

    protected const HEADLINE_MAX = 256;

    protected const CAPTION_MAX = 2000;

    protected const KEYWORD_MAX = 64;

    /**
     * @param  list<string>  $keywords
     */
    public function embed(string $path, string $title, string $description, array $keywords): void
    {
        $jpeg = file_get_contents($path);

        if ($jpeg === false || ! str_starts_with($jpeg, "\xFF\xD8")) {
            throw new RuntimeException('Not a JPEG file.');
        }

        file_put_contents($path, $this->withXmp($jpeg, $this->xmp($title, $description, $keywords)));

        $result = iptcembed($this->iptc($title, $description, $keywords), $path);

        if ($result === false) {
            throw new RuntimeException('Could not embed IPTC.');
        }

        file_put_contents($path, $result);
    }

    /**
     * @param  list<string>  $keywords
     */
    protected function iptc(string $title, string $description, array $keywords): string
    {
        $data = $this->dataset(1, 90, "\x1B%G")
            .$this->dataset(2, 0, "\x00\x04");

        if ($title !== '') {
            $data .= $this->dataset(2, 5, mb_strcut($title, 0, self::OBJECT_NAME_MAX, 'UTF-8'))
                .$this->dataset(2, 105, mb_strcut($title, 0, self::HEADLINE_MAX, 'UTF-8'));
        }

        foreach ($keywords as $keyword) {
            $data .= $this->dataset(2, 25, mb_strcut($keyword, 0, self::KEYWORD_MAX, 'UTF-8'));
        }

        if ($description !== '') {
            $data .= $this->dataset(2, 120, mb_strcut($description, 0, self::CAPTION_MAX, 'UTF-8'));
        }

        return $data;
    }

    protected function dataset(int $record, int $number, string $value): string
    {
        return "\x1C".chr($record).chr($number).pack('n', strlen($value)).$value;
    }

    /**
     * @param  list<string>  $keywords
     */
    protected function xmp(string $title, string $description, array $keywords): string
    {
        $e = fn (string $text) => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $alt = fn (string $text) => '<rdf:Alt><rdf:li xml:lang="x-default">'.$e($text).'</rdf:li></rdf:Alt>';
        $subjects = implode('', array_map(fn (string $keyword) => '<rdf:li>'.$e($keyword).'</rdf:li>', $keywords));

        return '<?xpacket begin="'."\u{FEFF}".'" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/">'
            .($title !== '' ? '<dc:title>'.$alt($title).'</dc:title><photoshop:Headline>'.$e($title).'</photoshop:Headline>' : '')
            .($description !== '' ? '<dc:description>'.$alt($description).'</dc:description>' : '')
            .($subjects !== '' ? '<dc:subject><rdf:Bag>'.$subjects.'</rdf:Bag></dc:subject>' : '')
            .'</rdf:Description></rdf:RDF></x:xmpmeta>'
            .'<?xpacket end="w"?>';
    }

    /**
     * The JPEG with its XMP segments (standard and extended) replaced by one new packet, placed after the
     * JFIF and Exif segments that open the file, where readers look for it.
     */
    protected function withXmp(string $jpeg, string $packet): string
    {
        $segments = [];
        $position = 2;
        $length = strlen($jpeg);

        while ($position + 4 <= $length && $jpeg[$position] === "\xFF") {
            $marker = ord($jpeg[$position + 1]);

            // Start of scan: the image data follows, no more metadata segments.
            if ($marker === 0xDA) {
                break;
            }

            $size = unpack('n', substr($jpeg, $position + 2, 2))[1];
            $segments[] = ['marker' => $marker, 'data' => substr($jpeg, $position, 2 + $size)];
            $position += 2 + $size;
        }

        $isXmp = fn (array $segment) => $segment['marker'] === 0xE1
            && (str_starts_with(substr($segment['data'], 4), self::XMP_HEADER) || str_starts_with(substr($segment['data'], 4), self::XMP_EXTENSION_HEADER));
        $segments = array_values(array_filter($segments, fn (array $segment) => ! $isXmp($segment)));

        $at = 0;

        while (isset($segments[$at]) && in_array($segments[$at]['marker'], [0xE0, 0xE1], true)) {
            $at++;
        }

        $xmp = self::XMP_HEADER.$packet;
        array_splice($segments, $at, 0, [['marker' => 0xE1, 'data' => "\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp]]);

        return "\xFF\xD8".implode('', array_column($segments, 'data')).substr($jpeg, $position);
    }
}
