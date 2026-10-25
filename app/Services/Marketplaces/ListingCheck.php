<?php

namespace App\Services\Marketplaces;

/**
 * Outcome of ListingChecks for one work and account.
 */
final readonly class ListingCheck
{
    /**
     * @param  string|null  $blocker  Why the work cannot go to this account at all (account off, type not accepted), a key under library.checks
     * @param  list<array{key: string, params: array<string, mixed>}>  $warnings  Rules it does not meet; sent anyway, fixed in the marketplace's portal
     * @param  list<array{key: string, params: array<string, mixed>}>  $errors  File limits the marketplace rejects on upload (resolution, size): not sent
     */
    public function __construct(public ?string $blocker = null, public array $warnings = [], public array $errors = []) {}

    public function sendable(): bool
    {
        return $this->blocker === null && ! $this->errors;
    }

    /**
     * Why the work is not sent, in the shape of the checks: the blocker first, then the file errors.
     *
     * @return list<array{key: string, params: array<string, mixed>}>
     */
    public function rejections(): array
    {
        return [...($this->blocker ? [['key' => $this->blocker, 'params' => []]] : []), ...$this->errors];
    }
}
