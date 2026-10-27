<?php

namespace Tests\Feature\Publishing;

use App\Services\Marketplaces\Delivery\DeliveryFailed;
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;

/**
 * An FTP server in memory: what was uploaded, under which name.
 */
class FakeFtpClient implements FtpClient
{
    /** @var list<array{string, int, bool}> */
    public array $connections = [];

    /** @var list<array{string, string}> */
    public array $logins = [];

    /** @var array<string, string> */
    public array $files = [];

    public int $puts = 0;

    public int $closed = 0;

    public ?string $loginFailure = null;

    /** Uploads that arrive cut short (the size check fails) */
    public int $shortUploads = 0;

    public function connect(string $address, int $port, bool $ssl): void
    {
        $this->connections[] = [$address, $port, $ssl];
    }

    public function login(string $login, #[\SensitiveParameter] string $password): void
    {
        if ($this->loginFailure) {
            throw new DeliveryFailed($this->loginFailure);
        }

        $this->logins[] = [$login, $password];
    }

    public function put(string $remote, string $local): bool
    {
        $this->puts++;
        $bytes = file_get_contents($local);

        if ($this->shortUploads > 0) {
            $this->shortUploads--;
            $bytes = substr($bytes, 0, -1);
        }

        $this->files[$remote] = $bytes;

        return true;
    }

    public function size(string $remote): int
    {
        return isset($this->files[$remote]) ? strlen($this->files[$remote]) : -1;
    }

    public function close(): void
    {
        $this->closed++;
    }
}
