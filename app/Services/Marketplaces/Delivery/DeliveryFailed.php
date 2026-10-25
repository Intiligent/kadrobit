<?php

namespace App\Services\Marketplaces\Delivery;

use RuntimeException;
use Throwable;

/**
 * An upload or sign-in that did not work, with a reason instead of the server's message: that text may echo
 * the login and is neither shown nor logged.
 */
class DeliveryFailed extends RuntimeException
{
    /**
     * @param  string  $reason  A ConnectionResult reason (auth, connect, other) or AssetListing::ERROR_FILE
     */
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Marketplace delivery failed: {$reason}.", 0, $previous);
    }
}
