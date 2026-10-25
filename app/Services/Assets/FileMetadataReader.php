<?php

namespace App\Services\Assets;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Title, description and keywords a contributor already put into the file (Lightroom, Bridge, Photoshop),
 * and whether the file says it was made by generative AI. Read on upload; what is found wins over generation.
 *
 * - XMP (dc:title, dc:description, dc:subject) from any file that embeds it uncompressed: JPEG, TIFF, PNG, EPS,
 *   SVG, MP4 with the packet near the start. It is UTF-8 and preferred.
 * - IPTC (Object Name / Headline, Caption-Abstract, Keywords) from JPEG, for files without XMP.
 * - AI: the IPTC digital source type "trainedAlgorithmicMedia" (or "compositeWithTrainedAlgorithmicMedia"),
 *   which both XMP and C2PA manifests carry as a plain URL.
 */
class FileMetadataReader
{
    /** Metadata sits at the start of the file; large originals are not read whole. */
    protected const SCAN_BYTES = 4 * 1024 * 1024;

    /** Same limits as UpdateAssetRequest. */
    public const TEXT_MAX = 200;

    public const KEYWORDS_MAX = 50;

    public const KEYWORD_MAX = 100;

    /**
     * @return array{title: ?string, description: ?string, keywords: list<string>, is_ai_generated: bool}
     */
    public function read(string $path, string $mime): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, self::SCAN_BYTES);
        $xmp = $this->xmp($head);
        $iptc = $mime === 'image/jpeg' ? $this->iptc($path) : [];

        return [
            'title' => $this->text($xmp['title'] ?? null) ?? $this->text($iptc['2#005'][0] ?? null) ?? $this->text($iptc['2#105'][0] ?? null),
            'description' => $this->text($xmp['description'] ?? null) ?? $this->text($iptc['2#120'][0] ?? null),
            'keywords' => $this->keywords(($xmp['keywords'] ?? []) ?: ($iptc['2#025'] ?? [])),
            'is_ai_generated' => str_contains($head, 'rainedAlgorithmicMedia'),
        ];
    }

    /**
     * @return array{title?: string, description?: string, keywords?: list<string>}
     */
    protected function xmp(string $head): array
    {
        $start = strpos($head, '<x:xmpmeta');
        $end = $start === false ? false : strpos($head, '</x:xmpmeta>', $start);

        if ($end === false) {
            return [];
        }

        $document = new DOMDocument;

        if (! @$document->loadXML(substr($head, $start, $end - $start + strlen('</x:xmpmeta>')), LIBXML_NONET)) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');

        $first = fn (string $query) => ($node = $xpath->query($query)->item(0)) ? $node->textContent : null;

        return array_filter([
            'title' => $first('//dc:title//rdf:li'),
            'description' => $first('//dc:description//rdf:li'),
            'keywords' => array_map(fn ($node) => $node->textContent, iterator_to_array($xpath->query('//dc:subject//rdf:li'))),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function iptc(string $path): array
    {
        @getimagesize($path, $info);

        return isset($info['APP13']) ? (iptcparse($info['APP13']) ?: []) : [];
    }

    /**
     * IPTC is often Latin-1 rather than UTF-8.
     */
    protected function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    protected function text(?string $text): ?string
    {
        $text = $text === null ? '' : Str::limit(Str::squish($this->utf8($text)), self::TEXT_MAX, '');

        return $text === '' ? null : $text;
    }

    /**
     * Stored like edited keywords: trimmed, lowercase, unique, in order, at most KEYWORDS_MAX of at most
     * KEYWORD_MAX characters. Some tools write the whole list as one entry ("cat, dog; pet"): it is split.
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    protected function keywords(array $keywords): array
    {
        return collect($keywords)
            ->flatMap(fn ($entry) => preg_split('/[,;]/', $this->utf8((string) $entry)))
            ->map(fn (string $word) => mb_substr(mb_strtolower(Str::squish($word)), 0, self::KEYWORD_MAX))
            ->filter()
            ->unique()
            ->take(self::KEYWORDS_MAX)
            ->values()
            ->all();
    }
}
