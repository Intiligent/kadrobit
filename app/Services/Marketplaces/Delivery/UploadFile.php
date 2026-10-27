<?php

namespace App\Services\Marketplaces\Delivery;

/**
 * A local file prepared for a marketplace, its name on the marketplace's server and the folder it goes to
 * ('' for the folder the sign-in lands in).
 */
final readonly class UploadFile
{
    public function __construct(public string $path, public string $remote, public string $directory = '') {}

    /** Where the file is written on the server. */
    public function remotePath(): string
    {
        return $this->directory !== '' ? "{$this->directory}/{$this->remote}" : $this->remote;
    }
}
