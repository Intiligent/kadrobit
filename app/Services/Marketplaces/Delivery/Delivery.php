<?php

namespace App\Services\Marketplaces\Delivery;

use App\Models\MarketplaceAccount;

/**
 * How works reach one marketplace: protocol, file names, metadata inside the file. Registered by marketplace code
 * in config/publishing.php (deliveries).
 */
interface Delivery
{
    /**
     * Signs in to the account's upload server once for a series of uploads.
     *
     * @throws DeliveryFailed with a ConnectionResult reason
     */
    public function open(MarketplaceAccount $account): DeliverySession;
}
