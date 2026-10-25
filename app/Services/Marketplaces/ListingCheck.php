<?php

namespace App\Services\Marketplaces;

/**
 * Outcome of ListingChecks for one work and account.
 */
final readonly class ListingCheck
{
    /**
     * @param  string|null  $blocker  Why the work cannot be sent at all, a key under library.checks
     * @param  list<array{key: string, params: array<string, mixed>}>  $warnings  Rules it does not meet; sent anyway
     */
    public function __construct(public ?string $blocker = null, public array $warnings = []) {}

    public function sendable(): bool
    {
        return $this->blocker === null;
    }
}
