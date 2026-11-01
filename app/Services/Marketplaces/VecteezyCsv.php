<?php

namespace App\Services\Marketplaces;

/**
 * Vecteezy's metadata CSV (docs/marketplaces/vecteezy/README.md): one row per work, sent with its files over SFTP.
 * Vecteezy applies a CSV before the metadata embedded in the file, so every field the work has goes here.
 *
 * Filename is the name on the server without the extension, as Vecteezy asks for SFTP uploads. License is pro, or
 * editorial for an editorial work: an editorial license can never be changed to free or pro later.
 */
class VecteezyCsv
{
    public const COLUMNS = ['Filename', 'Title', 'Description', 'Keywords', 'License'];

    /**
     * @param  array{title: string, description: string, keywords: list<string>}  $texts
     */
    public function build(string $remote, array $texts, bool $editorial): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, self::COLUMNS, escape: '');
        fputcsv($out, [
            pathinfo($remote, PATHINFO_FILENAME),
            $texts['title'],
            $texts['description'],
            implode(',', $texts['keywords']),
            $editorial ? 'editorial' : 'pro',
        ], escape: '');

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
