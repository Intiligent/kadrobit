<?php

namespace App\Services\Marketplaces;

use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\Ftp\FtpConnectionProvider;
use League\Flysystem\Ftp\UnableToAuthenticate;
use phpseclib4\Net\SFTP;
use Throwable;

/**
 * Signs in to a marketplace upload server to check host, login and password.
 *
 * FTP and FTPS go through league/flysystem-ftp, the driver of the upload service. SFTP uses phpseclib
 * directly: league/flysystem-sftp-v3 needs phpseclib 3, while Passport and Socialite need phpseclib 4.
 */
class ConnectionTester
{
    /**
     * @param  array{host: string, port: int|null, login: string, password: string}  $connection
     */
    public function test(Marketplace $marketplace, #[\SensitiveParameter] array $connection): ConnectionResult
    {
        $exception = null;

        try {
            $signedIn = match ($marketplace->upload_protocol) {
                UploadProtocol::Ftp => $this->signInFtp($connection, ssl: false),
                UploadProtocol::Ftps => $this->signInFtp($connection, ssl: true),
                UploadProtocol::Sftp => $this->signInSftp($connection),
                default => throw new InvalidArgumentException("No automated upload for protocol {$marketplace->upload_protocol->value}."),
            };

            $result = $signedIn ? ConnectionResult::ok() : ConnectionResult::failed(ConnectionResult::AUTH);
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
     * Connects, signs in and turns on passive mode, as the upload service will.
     */
    protected function signInFtp(#[\SensitiveParameter] array $connection, bool $ssl): bool
    {
        $options = FtpConnectionOptions::fromArray([
            'host' => $connection['host'],
            'port' => $connection['port'] ?? 21,
            'username' => $connection['login'],
            'password' => $connection['password'],
            'ssl' => $ssl,
            'passive' => true,
            // Data connections go to the host we signed in to, not to an address the server names.
            'ignorePassiveAddress' => true,
            'timeout' => config('credentials.connect_timeout'),
            'root' => '',
        ]);

        try {
            ftp_close((new FtpConnectionProvider)->createConnection($options));

            return true;
        } catch (UnableToAuthenticate) {
            return false;
        }
    }

    protected function signInSftp(#[\SensitiveParameter] array $connection): bool
    {
        $sftp = new SFTP($connection['host'], $connection['port'] ?? 22, config('credentials.connect_timeout'));

        try {
            return $sftp->login($connection['login'], $connection['password']);
        } finally {
            $sftp->disconnect();
        }
    }
}
