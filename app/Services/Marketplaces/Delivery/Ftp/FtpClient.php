<?php

namespace App\Services\Marketplaces\Delivery\Ftp;

use App\Services\Marketplaces\Delivery\DeliveryFailed;

/**
 * One FTP or FTPS connection, so deliveries can be tested without a server (NativeFtpClient is the real one).
 */
interface FtpClient
{
    /**
     * Connects to an address already resolved and checked (HostResolver::publicAddress()).
     *
     * @throws DeliveryFailed connect
     */
    public function connect(string $address, int $port, bool $ssl): void;

    /**
     * Signs in and turns on passive mode.
     *
     * @throws DeliveryFailed auth for a rejected login, other for a failed TLS handshake
     */
    public function login(string $login, #[\SensitiveParameter] string $password): void;

    /** Uploads a local file in binary mode; false when the transfer did not complete. */
    public function put(string $remote, string $local): bool;

    /** Size of a file on the server, or -1 when the server does not say. */
    public function size(string $remote): int;

    /** Never throws. */
    public function close(): void;
}
