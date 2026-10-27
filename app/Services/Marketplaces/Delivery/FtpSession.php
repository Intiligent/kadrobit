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
 * The listings it uploaded are handed to FtpDelivery::finished() when it closes, before it signs out.
 */
class FtpSession implements DeliverySession
{
    /** @var list<AssetListing> */
    protected array $sent = [];

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

            $this->sent[] = $listing;

            return $files[0]->remote;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function close(): void
    {
        // Still signed in: a marketplace may send more for the uploaded works (Dreamstime's CSV).
        if ($this->sent) {
            $sent = $this->sent;
            $this->sent = [];
            $this->delivery->finished($this, $sent);
        }

        $this->client->close();
    }

    /**
     * Uploads one file, also outside a listing (a marketplace's metadata CSV).
     *
     * @throws DeliveryFailed connect
     */
    public function upload(UploadFile $file): void
    {
        $size = filesize($file->path);
        $remote = $file->remotePath();

        for ($try = 1; $try <= 2; $try++) {
            if ($this->client->put($remote, $file->path) && in_array($this->client->size($remote), [$size, -1], true)) {
                return;
            }
        }

        throw new DeliveryFailed(ConnectionResult::CONNECT);
    }
}
