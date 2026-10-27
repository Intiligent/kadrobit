<?php

namespace App\Services\Marketplaces;

use App\Enums\MediaType;
use App\Models\AssetListing;
use App\Models\Marketplace;

/**
 * Dreamstime's metadata CSV for files already uploaded over FTP (docs/marketplaces/dreamstime/README.md, templates/):
 * one for images (photos, illustrations, the JPEG of a vector) and one for videos, whose columns differ.
 *
 * Rows use the work's current metadata (AssetListing::snapshot()): the CSV goes after the files, and edits made in
 * between reach Dreamstime too. Filename is the name on the server (AssetListing::remote_filename). Extended licenses,
 * the free section and releases are left off: Kadrobit has no such settings, and release IDs are given by Dreamstime
 * only after the releases are uploaded.
 */
class DreamstimeCsv
{
    public const IMAGE_COLUMNS = ['Filename', 'Image Name', 'Description', 'Category 1', 'Category 2', 'Category 3', 'keywords', 'Free', 'W-EL', 'P-EL', 'SR-EL', 'SR-Price', 'Editorial', 'MR doc Ids', 'Pr Docs'];

    public const VIDEO_COLUMNS = ['Filename', 'Video Name', 'Description', 'Category 1', 'Category 2', 'Category 3', 'keywords', 'W-EL', 'SR-EL', 'SR-Price', 'Editorial', 'MR doc Ids', 'Pr Docs'];

    /** Categories a work takes. */
    public const CATEGORIES_MAX = 3;

    /**
     * @param  iterable<AssetListing>  $listings  Sent listings with their asset
     * @return array{images: ?string, videos: ?string} null for a kind without rows
     */
    public function build(iterable $listings, Marketplace $marketplace): array
    {
        $rows = ['images' => [], 'videos' => []];

        foreach ($listings as $listing) {
            $video = $listing->asset->type === MediaType::Video;
            $rows[$video ? 'videos' : 'images'][] = $this->row($listing->remote_filename, AssetListing::snapshot($listing->asset, $marketplace), $video);
        }

        return [
            'images' => $rows['images'] ? $this->csv(self::IMAGE_COLUMNS, $rows['images']) : null,
            'videos' => $rows['videos'] ? $this->csv(self::VIDEO_COLUMNS, $rows['videos']) : null,
        ];
    }

    /**
     * @param  array{title: string, description: string, keywords: list<string>, categories: list<string>, is_editorial: bool}  $metadata
     * @return list<string|int>
     */
    protected function row(?string $filename, array $metadata, bool $video): array
    {
        $title = $metadata['title'] ?? '';
        $description = $metadata['description'] ?? '';
        // Unused category columns take 0.
        $categories = array_pad(array_slice(array_map('intval', $metadata['categories'] ?? []), 0, self::CATEGORIES_MAX), self::CATEGORIES_MAX, 0);

        return [
            $filename,
            $title !== '' ? $title : $description,
            $description !== '' ? $description : $title,
            ...$categories,
            implode(',', $metadata['keywords'] ?? []),
            // Free (images only), W-EL, P-EL (images only), SR-EL, SR-Price
            ...($video ? [0, 0, 0] : [0, 0, 0, 0, 0]),
            ($metadata['is_editorial'] ?? false) ? 1 : 0,
            '',
            '',
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string|int>>  $rows
     */
    protected function csv(array $columns, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $columns, escape: '');

        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
