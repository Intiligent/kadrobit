<?php

namespace App\Services\Marketplaces\Delivery;

use App\Models\AssetListing;

/**
 * A signed-in connection to a marketplace's upload server (Delivery::open()).
 */
interface DeliverySession
{
    /**
     * Uploads the listing's work with the metadata in $listing->metadata (taken when the upload started, see
     * AssetListing::snapshot()), and returns the name of the file on the server.
     *
     * A "connect" failure means the connection is lost: the session is not used again.
     *
     * @throws DeliveryFailed
     */
    public function send(AssetListing $listing): string;

    /** Signs out; never throws. */
    public function close(): void;
}
