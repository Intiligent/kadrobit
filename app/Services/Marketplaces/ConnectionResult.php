<?php

namespace App\Services\Marketplaces;

use League\Flysystem\Ftp\UnableToConnectToFtpHost;
use phpseclib4\Exception\ConnectionClosedException;
use phpseclib4\Exception\ServiceUnavailableException;
use phpseclib4\Exception\TimeoutException;
use Throwable;

/**
 * Outcome of a sign-in to a marketplace upload server. Carries a reason, never the server's message:
 * that text may echo the login and is not shown or logged.
 */
final readonly class ConnectionResult
{
    public const AUTH = 'auth';

    public const CONNECT = 'connect';

    public const OTHER = 'other';

    private function __construct(public bool $ok, public ?string $reason = null) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * A rejected login is reported by the tester itself; exceptions are about reaching the server.
     */
    public static function fromException(Throwable $e): self
    {
        return self::failed(match (true) {
            $e instanceof UnableToConnectToFtpHost,
            $e instanceof ServiceUnavailableException,
            $e instanceof TimeoutException,
            $e instanceof ConnectionClosedException => self::CONNECT,
            default => self::OTHER,
        });
    }

    public function isAuthError(): bool
    {
        return $this->reason === self::AUTH;
    }
}
