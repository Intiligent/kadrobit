<?php

namespace App\Services\Marketplaces\Delivery;

/**
 * A local file prepared for a marketplace, and its name on the marketplace's server.
 */
final readonly class UploadFile
{
    public function __construct(public string $path, public string $remote) {}
}
