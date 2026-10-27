<?php

namespace App\Services\Marketplaces\Delivery;

use App\Models\AssetListing;
use Illuminate\Support\Str;

/**
 * The name of a work's file on a marketplace's server: its original name, made safe for FTP, with a short suffix
 * from the work's id when another work already sent to the account has that name (sunset-7f3a.jpg).
 * The same work always gets the same name, so sending it again replaces its file instead of adding a copy.
 */
class RemoteFilename
{
    /** Longest name kept before the extension. */
    protected const BASE_MAX = 120;

    /**
     * @param  string|null  $extension  Another extension than the original's, for a marketplace that gets a copy in
     *                                  another format (the JPEG of a vector): taken into account before the check
     *                                  for a name already in use
     */
    public static function for(AssetListing $listing, ?string $extension = null): string
    {
        $asset = $listing->asset;
        $name = self::safe($asset->original_filename, $asset->ulid);

        if ($extension !== null) {
            $name = self::withExtension($name, $extension);
        }

        $taken = AssetListing::query()
            ->where('marketplace_account_id', $listing->marketplace_account_id)
            ->where('asset_id', '!=', $asset->id)
            ->whereRaw('lower(remote_filename) = ?', [Str::lower($name)])
            ->exists();

        return $taken ? self::withSuffix($name, Str::lower(substr($asset->ulid, -4))) : $name;
    }

    /**
     * ASCII letters, digits, spaces, dots, dashes, underscores and brackets: names that every FTP server and the
     * marketplace's CSV take as they are. A name with nothing left becomes the work's id.
     */
    public static function safe(string $original, string $fallback): string
    {
        $extension = Str::lower(pathinfo($original, PATHINFO_EXTENSION));
        $base = Str::ascii(pathinfo($original, PATHINFO_FILENAME));
        $base = trim(preg_replace(['/[^A-Za-z0-9 ._()\-]+/', '/\s+/'], ['-', ' '], $base), ' .-');
        $base = Str::limit($base, self::BASE_MAX, '') ?: Str::lower($fallback);

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }

    /** The same name with another extension, for a file sent alongside (the JPEG of a vector). */
    public static function withExtension(string $name, string $extension): string
    {
        return pathinfo($name, PATHINFO_FILENAME).'.'.$extension;
    }

    protected static function withSuffix(string $name, string $suffix): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return pathinfo($name, PATHINFO_FILENAME)."-{$suffix}".($extension !== '' ? ".{$extension}" : '');
    }
}
