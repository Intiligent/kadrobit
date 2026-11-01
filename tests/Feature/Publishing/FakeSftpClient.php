<?php

namespace Tests\Feature\Publishing;

use App\Services\Marketplaces\Delivery\Ftp\SftpClient;

/**
 * An SFTP server in memory, the same as FakeFtpClient.
 */
class FakeSftpClient extends FakeFtpClient implements SftpClient {}
