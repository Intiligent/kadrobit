<?php

namespace App\Services\Marketplaces\Delivery;

use App\Models\AssetListing;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A signed-in FTP or FTPS connection (FtpDelivery::open()). Each file is checked after the upload by its size on
 * the server and sent once more if it does not match; a second failure means the connection is lost.
 */
class FtpSession implements DeliverySession
{
    public function __construct(protected FtpClient $client, protected FtpDelivery $delivery) {}

    public function send(AssetListing $listing): string
    {
        $directory = storage_path('app/private/publishing/'.Str::uuid());
        File::ensureDirectoryExists($directory);

        try {
            $files = $this->delivery->package($listing, $directory);

            foreach ($files as $file) {
                $this->upload($file);
            }

            return $files[0]->remote;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function close(): void
    {
        $this->client->close();
    }

    /**
     * @throws DeliveryFailed connect
     */
    protected function upload(UploadFile $file): void
    {
        $size = filesize($file->path);

        for ($try = 1; $try <= 2; $try++) {
            if ($this->client->put($file->remote, $file->path) && in_array($this->client->size($file->remote), [$size, -1], true)) {
                return;
            }
        }

        throw new DeliveryFailed(ConnectionResult::CONNECT);
    }
}
