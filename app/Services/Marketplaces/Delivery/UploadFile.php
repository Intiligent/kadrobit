<?php

namespace App\Services\Marketplaces\Delivery;

/**
 * A local file prepared for a marketplace, its name on the marketplace's server and the folder it goes to
 * ('' for the folder the sign-in lands in).
 *
 * A file sent ahead goes before the work's own files and is never the name the listing records: a per-work metadata
 * CSV that must be on the server when the marketplace picks the work up (Vecteezy).
 */
final readonly class UploadFile
{
    public function __construct(public string $path, public string $remote, public string $directory = '', public bool $ahead = false) {}

    /** Where the file is written on the server. */
    public function remotePath(): string
    {
        return $this->directory !== '' ? "{$this->directory}/{$this->remote}" : $this->remote;
    }
}
