<?php

namespace App\Services\Marketplaces\Delivery\Ftp;

use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\DeliveryFailed;
use FTP\Connection;

/**
 * FtpClient on the ftp extension, as ConnectionTester signs in: the TLS handshake of FTPS runs inside
 * ftp_login(), so its failure is told from a rejected password by PHP's warning.
 */
class NativeFtpClient implements FtpClient
{
    protected ?Connection $ftp = null;

    public function connect(string $address, int $port, bool $ssl): void
    {
        $timeout = config('credentials.connect_timeout');
        $ftp = $ssl ? @ftp_ssl_connect($address, $port, $timeout) : @ftp_connect($address, $port, $timeout);

        if ($ftp === false) {
            throw new DeliveryFailed(ConnectionResult::CONNECT);
        }

        $this->ftp = $ftp;
    }

    public function login(string $login, #[\SensitiveParameter] string $password): void
    {
        error_clear_last();

        if (! @ftp_login($this->ftp, $login, $password)) {
            throw new DeliveryFailed(ConnectionResult::fromFtpLoginWarning(error_get_last()['message'] ?? null)->reason);
        }

        // Data connections go to the address we signed in to, not to one the server names in its PASV answer.
        ftp_set_option($this->ftp, FTP_USEPASVADDRESS, false);
        // A large file is sent in many writes; each may wait this long.
        ftp_set_option($this->ftp, FTP_TIMEOUT_SEC, config('publishing.transfer_timeout'));

        if (! @ftp_pasv($this->ftp, true)) {
            throw new DeliveryFailed(ConnectionResult::OTHER);
        }
    }

    public function put(string $remote, string $local): bool
    {
        return @ftp_put($this->ftp, $remote, $local, FTP_BINARY);
    }

    public function size(string $remote): int
    {
        return @ftp_size($this->ftp, $remote);
    }

    public function close(): void
    {
        if ($this->ftp) {
            @ftp_close($this->ftp);
            $this->ftp = null;
        }
    }
}
