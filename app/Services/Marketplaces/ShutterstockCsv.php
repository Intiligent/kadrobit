<?php

namespace App\Services\Marketplaces;

use App\Enums\MediaType;
use App\Models\AssetListing;

/**
 * Shutterstock's metadata CSV for files already on its server (metadata_rules.csv_fields): the only way to set
 * categories, and to update the metadata of a sent file without uploading it again (that would add a copy). It is
 * taken on the website only, not over FTPS; sending it in the contributor's browser is KB-84.
 *
 * Rows use the work's current metadata; Filename is the name on the server (AssetListing::remote_filename).
 */
class ShutterstockCsv
{
    public const COLUMNS = ['Filename', 'Description', 'Keywords', 'Categories', 'Illustration', 'Mature Content', 'Editorial'];

    /**
     * @param  iterable<AssetListing>  $listings  Sent listings with their asset
     */
    public function build(iterable $listings): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, self::COLUMNS, escape: '');

        foreach ($listings as $listing) {
            $asset = $listing->asset;

            fputcsv($out, [
                $listing->remote_filename,
                $asset->description ?: $asset->title,
                implode(',', $asset->keywords ?? []),
                implode(',', $asset->categories['shutterstock'] ?? []),
                in_array($asset->type, [MediaType::Illustration, MediaType::Vector], true) ? 'yes' : 'no',
                'no',
                $asset->is_editorial ? 'yes' : 'no',
            ], escape: '');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
