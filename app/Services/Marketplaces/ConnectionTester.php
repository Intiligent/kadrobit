<?php

namespace App\Services\Marketplaces;

use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use App\Support\HostResolver;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use phpseclib4\Net\SFTP;
use Throwable;

/**
 * Signs in to a marketplace upload server to check host, login and password.
 *
 * The host is resolved first and the connection goes to the resolved address, which must be public:
 * the check runs again on every sign-in, so a name pointed at an internal address later is caught too.
 *
 * FTP and FTPS use the ftp extension directly, to tell a failed TLS handshake from a rejected login.
 * SFTP uses phpseclib: league/flysystem-sftp-v3 needs phpseclib 3, while Passport and Socialite need 4.
 */
class ConnectionTester
{
    public function __construct(protected HostResolver $resolver) {}

    /**
     * @param  array{host: string, port: int|null, login: string, password: string}  $connection
     */
    public function test(Marketplace $marketplace, #[\SensitiveParameter] array $connection): ConnectionResult
    {
        $exception = null;
        $address = $this->resolver->publicAddress($connection['host']);

        if ($address === null) {
            // Either the name does not resolve or it leads to a private address: nothing is contacted.
            Log::warning('Marketplace server address not allowed or not found', [
                'marketplace' => $marketplace->code,
                'host' => $connection['host'],
            ]);

            return ConnectionResult::failed(ConnectionResult::CONNECT);
        }

        try {
            $result = match ($marketplace->upload_protocol) {
                UploadProtocol::Ftp => $this->signInFtp($address, $connection, ssl: false),
                UploadProtocol::Ftps => $this->signInFtp($address, $connection, ssl: true),
                UploadProtocol::Sftp => $this->signInSftp($address, $connection),
                default => throw new InvalidArgumentException("No automated upload for protocol {$marketplace->upload_protocol->value}."),
            };
        } catch (Throwable $e) {
            $exception = $e::class;
            $result = ConnectionResult::fromException($e);
        }

        if (! $result->ok) {
            // Only the class and reason: the server's message may contain the login.
            Log::info('Marketplace connection check failed', [
                'marketplace' => $marketplace->code,
                'reason' => $result->reason,
                'exception' => $exception,
            ]);
        }

        return $result;
    }

    /**
     * Connects, signs in and turns on passive mode, as the upload service will. Data connections go to
     * the address we signed in to, not to one the server names in its PASV answer.
     */
    protected function signInFtp(string $address, #[\SensitiveParameter] array $connection, bool $ssl): ConnectionResult
    {
        $port = $connection['port'] ?? 21;
        $timeout = config('credentials.connect_timeout');
        $ftp = $ssl ? @ftp_ssl_connect($address, $port, $timeout) : @ftp_connect($address, $port, $timeout);

        if ($ftp === false) {
            return ConnectionResult::failed(ConnectionResult::CONNECT);
        }

        try {
            error_clear_last();

            if (! @ftp_login($ftp, $connection['login'], $connection['password'])) {
                return ConnectionResult::fromFtpLoginWarning(error_get_last()['message'] ?? null);
            }

            ftp_set_option($ftp, FTP_USEPASVADDRESS, false);

            return @ftp_pasv($ftp, true) ? ConnectionResult::ok() : ConnectionResult::failed(ConnectionResult::OTHER);
        } finally {
            @ftp_close($ftp);
        }
    }

    protected function signInSftp(string $address, #[\SensitiveParameter] array $connection): ConnectionResult
    {
        // fsockopen() needs an IPv6 address in brackets.
        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $sftp = new SFTP($host, $connection['port'] ?? 22, config('credentials.connect_timeout'));

        try {
            return $sftp->login($connection['login'], $connection['password'])
                ? ConnectionResult::ok()
                : ConnectionResult::failed(ConnectionResult::AUTH);
        } finally {
            $sftp->disconnect();
        }
    }
}
