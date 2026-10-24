<?php

namespace Tests\Feature\Settings;

use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\ConnectionTester;
use App\Support\HostResolver;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectionTesterTest extends TestCase
{
    /**
     * @return array<string, array{UploadProtocol, string}>
     */
    public static function protocolsAndAddresses(): array
    {
        return [
            'ftp' => [UploadProtocol::Ftp, '127.0.0.1'],
            'ftps' => [UploadProtocol::Ftps, '127.0.0.1'],
            'sftp' => [UploadProtocol::Sftp, '127.0.0.1'],
            'ftp over ipv6' => [UploadProtocol::Ftp, '::1'],
            'sftp over ipv6' => [UploadProtocol::Sftp, '::1'],
        ];
    }

    protected function check(UploadProtocol $protocol, string $host): ConnectionResult
    {
        return app(ConnectionTester::class)->test(new Marketplace(['code' => 'test', 'upload_protocol' => $protocol]), [
            'host' => $host,
            'port' => 1,
            'login' => 'akadrov',
            'password' => 'secret-ftp-password',
        ]);
    }

    #[DataProvider('protocolsAndAddresses')]
    public function test_closed_port_is_reported_as_unreachable_without_the_password_in_the_log(UploadProtocol $protocol, string $host): void
    {
        // Loopback is refused in production; allowed here to reach a port nothing listens on.
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function isPublic(string $address): bool
            {
                return true;
            }
        });
        Log::spy();

        $result = $this->check($protocol, $host);

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionResult::CONNECT, $result->reason);

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'connect'
            && ! str_contains(json_encode($context), 'secret-ftp-password')
            && ! str_contains(json_encode($context), 'akadrov'));
    }

    public function test_private_address_is_not_contacted(): void
    {
        Log::spy();

        foreach (['127.0.0.1', 'localhost'] as $host) {
            $this->assertSame(ConnectionResult::CONNECT, $this->check(UploadProtocol::Ftp, $host)->reason);
        }

        Log::shouldHaveReceived('warning')->twice();
        Log::shouldNotHaveReceived('info');
    }
}
