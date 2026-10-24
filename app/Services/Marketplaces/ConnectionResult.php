<?php

namespace App\Services\Marketplaces;

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
     * Exceptions are about reaching the server, except an SFTP server asking to change an expired
     * password: phpseclib reports it with the same exception as an unreachable server.
     */
    public static function fromException(Throwable $e): self
    {
        return self::failed(match (true) {
            $e instanceof ServiceUnavailableException && str_contains($e->getMessage(), 'change the password') => self::AUTH,
            $e instanceof ServiceUnavailableException,
            $e instanceof TimeoutException,
            $e instanceof ConnectionClosedException => self::CONNECT,
            default => self::OTHER,
        });
    }

    /**
     * A failed ftp_login(). With FTPS the TLS handshake runs inside ftp_login(), so its failure must not
     * read as a wrong password; PHP names it in the warning ("SSL/TLS handshake failed",
     * "Server doesn't support FTPS."). Any other warning carries the server's answer to the login.
     */
    public static function fromFtpLoginWarning(?string $warning): self
    {
        return self::failed(preg_match('/SSL|TLS|FTPS/', $warning ?? '') ? self::OTHER : self::AUTH);
    }

    public function isAuthError(): bool
    {
        return $this->reason === self::AUTH;
    }
}
