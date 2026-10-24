<?php

namespace Tests\Feature\Settings;

use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\ConnectionTester;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectionTesterTest extends TestCase
{
    /**
     * @return array<string, array{UploadProtocol}>
     */
    public static function protocols(): array
    {
        return [
            'ftp' => [UploadProtocol::Ftp],
            'ftps' => [UploadProtocol::Ftps],
            'sftp' => [UploadProtocol::Sftp],
        ];
    }

    #[DataProvider('protocols')]
    public function test_closed_port_is_reported_as_unreachable_without_the_password_in_the_log(UploadProtocol $protocol): void
    {
        Log::spy();
        $marketplace = new Marketplace(['code' => 'test', 'upload_protocol' => $protocol]);

        // Nothing listens on port 1: the connection is refused at once.
        $result = app(ConnectionTester::class)->test($marketplace, [
            'host' => '127.0.0.1',
            'port' => 1,
            'login' => 'akadrov',
            'password' => 'secret-ftp-password',
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame(ConnectionResult::CONNECT, $result->reason);

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $context['reason'] === 'connect'
            && ! str_contains(json_encode($context), 'secret-ftp-password')
            && ! str_contains(json_encode($context), 'akadrov'));
    }
}
