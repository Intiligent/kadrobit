<?php

namespace App\Services\Marketplaces\Delivery\Ftp;

use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\DeliveryFailed;
use phpseclib4\Net\SFTP;
use Throwable;

/**
 * SftpClient on phpseclib, as ConnectionTester signs in. phpseclib throws on a broken connection; every call turns
 * that into a DeliveryFailed or a false result, as NativeFtpClient does.
 */
class PhpseclibSftpClient implements SftpClient
{
    protected ?SFTP $sftp = null;

    public function connect(string $address, int $port, bool $ssl): void
    {
        // fsockopen() needs an IPv6 address in brackets. phpseclib connects on login().
        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $this->sftp = new SFTP($host, $port, config('credentials.connect_timeout'));
    }

    public function login(string $login, #[\SensitiveParameter] string $password): void
    {
        try {
            $signedIn = $this->sftp->login($login, $password);
        } catch (Throwable $e) {
            // The connection itself is made here: an unreachable server fails with connect.
            throw new DeliveryFailed(ConnectionResult::fromException($e)->reason, $e);
        }

        if (! $signedIn) {
            throw new DeliveryFailed(ConnectionResult::AUTH);
        }

        // A large file is sent in many writes; each may wait this long.
        $this->sftp->setTimeout(config('publishing.transfer_timeout'));
    }

    public function put(string $remote, string $local): bool
    {
        try {
            $this->sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function size(string $remote): int
    {
        try {
            return (int) $this->sftp->filesize($remote);
        } catch (Throwable) {
            return -1;
        }
    }

    public function close(): void
    {
        if ($this->sftp) {
            rescue(fn () => $this->sftp->disconnect(), report: false);
            $this->sftp = null;
        }
    }
}
