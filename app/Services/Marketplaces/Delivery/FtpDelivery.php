<?php

namespace App\Services\Marketplaces\Delivery;

use App\Enums\AssetFileRole;
use App\Enums\UploadProtocol;
use App\Models\AssetFile;
use App\Models\AssetListing;
use App\Models\MarketplaceAccount;
use App\Services\Marketplaces\ConnectionResult;
use App\Services\Marketplaces\Delivery\Ftp\FtpClient;
use App\Support\HostResolver;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Delivery over FTP or FTPS (the marketplace's upload_protocol): one sign-in per session, files in binary mode,
 * passive mode with data connections to the address we signed in to. A marketplace adds what it sends for a
 * listing (package()): which files, their names, the metadata inside.
 *
 * Like ConnectionTester, the host is resolved first and the connection goes to the resolved address, which must
 * be public: a name pointed at an internal address is never contacted.
 */
abstract class FtpDelivery implements Delivery
{
    public function __construct(protected HostResolver $resolver, protected Container $container) {}

    public function open(MarketplaceAccount $account): DeliverySession
    {
        $marketplace = $account->marketplace;
        $host = $account->host ?: ($marketplace->upload_defaults['host'] ?? null);
        $address = $host ? $this->resolver->publicAddress($host) : null;

        if ($address === null) {
            throw new DeliveryFailed(ConnectionResult::CONNECT);
        }

        $client = $this->container->make(FtpClient::class);
        $client->connect($address, $account->port ?? 21, $marketplace->upload_protocol === UploadProtocol::Ftps);

        try {
            $client->login((string) $account->login, $account->credentials['password'] ?? '');
        } catch (Throwable $e) {
            $client->close();

            throw $e;
        }

        return new FtpSession($client, $this);
    }

    /**
     * The files to upload for a listing, the work's own file first: its name is what the listing records.
     * Files are written to $directory, which the session removes afterwards.
     *
     * @return list<UploadFile>
     *
     * @throws DeliveryFailed
     */
    abstract public function package(AssetListing $listing, string $directory): array;

    /**
     * Copies a work's file from storage to a local path.
     *
     * @throws DeliveryFailed file, when it cannot be read
     */
    protected function download(?AssetFile $file, string $path): string
    {
        $stream = $file ? rescue(fn () => $file->storage()->readStream($file->path), null, false) : null;

        if (! $stream) {
            throw new DeliveryFailed(AssetListing::ERROR_FILE);
        }

        try {
            $target = fopen($path, 'wb');
            $copied = stream_copy_to_stream($stream, $target);
            fclose($target);
        } finally {
            fclose($stream);
        }

        if ($copied === false || $copied !== $file->size_bytes) {
            throw new DeliveryFailed(AssetListing::ERROR_FILE);
        }

        return $path;
    }

    /**
     * Title and description to embed, from the listing's metadata (taken when the upload started). A marketplace
     * with one text field (metadata_rules.text_fields) gets that text in both, so whichever it reads is right.
     *
     * @return array{title: string, description: string, keywords: list<string>}
     */
    protected function texts(AssetListing $listing): array
    {
        $metadata = $listing->metadata ?? [];
        $fields = array_column($listing->account->marketplace->metadata_rules['text_fields'] ?? [], 'field');
        $title = in_array('title', $fields, true) || ! $fields ? ($metadata['title'] ?? '') : '';
        $description = in_array('description', $fields, true) || ! $fields ? ($metadata['description'] ?? '') : '';

        return [
            'title' => $title !== '' ? $title : $description,
            'description' => $description !== '' ? $description : $title,
            'keywords' => $metadata['keywords'] ?? [],
        ];
    }

    protected function original(AssetListing $listing): ?AssetFile
    {
        return $listing->asset->file(AssetFileRole::Original);
    }
}
