<?php

namespace App\Services\Marketplaces\Delivery;

use App\Models\Marketplace;
use Illuminate\Contracts\Container\Container;

/**
 * Picks the delivery of a marketplace from config/publishing.php (deliveries).
 */
class DeliveryFactory
{
    public function __construct(protected Container $container) {}

    /** Null when Kadrobit cannot upload to the marketplace yet. */
    public function for(Marketplace $marketplace): ?Delivery
    {
        $class = config("publishing.deliveries.{$marketplace->code}");

        return $class ? $this->container->make($class) : null;
    }
}
