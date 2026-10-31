<?php

namespace Tests\Unit\Marketplaces;

use App\Services\Marketplaces\ConnectionResult;
use phpseclib4\Exception\ServiceUnavailableException;
use phpseclib4\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConnectionResultTest extends TestCase
{
    public function test_ftps_handshake_failure_is_not_a_wrong_password(): void
    {
        $this->assertSame(ConnectionResult::OTHER, ConnectionResult::fromFtpLoginWarning('ftp_login(): SSL/TLS handshake failed')->reason);
        $this->assertSame(ConnectionResult::OTHER, ConnectionResult::fromFtpLoginWarning("ftp_login(): Server doesn't support FTPS.")->reason);
        // ftp.depositphotos.com lists AUTH TLS in FEAT, then refuses it.
        $this->assertSame(ConnectionResult::OTHER, ConnectionResult::fromFtpLoginWarning('ftp_login(): This security scheme is not implemented')->reason);
    }

    public function test_rejected_ftp_login_is_a_wrong_password(): void
    {
        $this->assertSame(ConnectionResult::AUTH, ConnectionResult::fromFtpLoginWarning('ftp_login(): Login incorrect.')->reason);
        $this->assertSame(ConnectionResult::AUTH, ConnectionResult::fromFtpLoginWarning(null)->reason);
    }

    public function test_expired_sftp_password_is_a_credentials_problem(): void
    {
        $e = new ServiceUnavailableException('The server is wanting you to change the password (Password expired)');

        $this->assertSame(ConnectionResult::AUTH, ConnectionResult::fromException($e)->reason);
    }

    public function test_unreachable_sftp_server_is_a_connection_problem(): void
    {
        $this->assertSame(ConnectionResult::CONNECT, ConnectionResult::fromException(new ServiceUnavailableException('Cannot connect to [::1]:22. Error 61.'))->reason);
        $this->assertSame(ConnectionResult::CONNECT, ConnectionResult::fromException(new TimeoutException('Connection timed out'))->reason);
        $this->assertSame(ConnectionResult::OTHER, ConnectionResult::fromException(new RuntimeException('Unexpected'))->reason);
    }
}
