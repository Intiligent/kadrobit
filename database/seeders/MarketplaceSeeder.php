<?php

namespace Database\Seeders;

use App\Enums\MediaType;
use App\Enums\MetadataDelivery;
use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use Illuminate\Database\Seeder;

/**
 * Reference data for stock marketplaces.
 *
 * Source: docs/Фотостоки API для загрузки фото с метаданными.md (20.10.2026).
 * None of the marketplaces offers a public contributor upload API:
 * files go over FTP/FTPS/SFTP, metadata via IPTC/XMP or CSV,
 * and the final submit for review is done in the web portal.
 *
 * TODO: supported_types are not covered by the research doc yet — verify per marketplace.
 * Logos: put files into storage/app/public/marketplaces/{code}.svg
 * ("public" disk; run `php artisan storage:link` once).
 *
 * TODO: file_rules and title/description limits — verify against contributor docs.
 */
class MarketplaceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->marketplaces() as $data) {
            Marketplace::updateOrCreate(
                ['code' => $data['code']],
                $data,
            );
        }
    }

    private function marketplaces(): array
    {
        $all = MediaType::cases();

        return [
            [
                'code' => 'adobe_stock',
                'name' => 'Adobe Stock',
                'logo' => 'marketplaces/adobe_stock.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Sftp,
                'upload_defaults' => [
                    // SFTP is available only for "qualified" contributor accounts.
                    'requires_approval' => true,
                ],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    'keywords_max' => 49,
                    // CSV "Category" column: one category number, optional.
                    'categories_min' => 0,
                    'categories_max' => 1,
                    'csv_max_rows' => 5000,
                    'csv_upload' => 'web',
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'shutterstock',
                'name' => 'Shutterstock',
                'logo' => 'marketplaces/shutterstock.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftps,
                'upload_defaults' => [
                    'host' => 'ftps.shutterstock.com',
                ],
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    'keywords_min' => 7,
                    'keywords_max' => 50,
                    'categories_min' => 1,
                    'categories_max' => 2,
                    'csv_fields' => ['Filename', 'Description', 'Keywords', 'Categories', 'Illustration', 'Mature Content', 'Editorial'],
                    // CSV is accepted only via the website, not over FTPS.
                    'csv_upload' => 'web',
                    'submit' => 'web',
                ],
                'file_rules' => [
                    'web_max_file_mb' => 50,
                    'web_max_files_per_batch' => 100,
                ],
            ],
            [
                'code' => 'istock',
                'name' => 'iStock / Getty Images',
                'logo' => 'marketplaces/istock.svg',
                // No FTP and no public API: web portal (ESP) only.
                'is_active' => false,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Web,
                'upload_defaults' => [],
                'metadata_delivery' => MetadataDelivery::Manual,
                'metadata_rules' => [
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'alamy',
                'name' => 'Alamy',
                'logo' => 'marketplaces/alamy.svg',
                'is_active' => true,
                'supported_types' => [MediaType::Photo, MediaType::Vector],
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'upload.alamy.com',
                    'port' => 21,
                ],
                // Captions and tags are set in Image Manager; IPTC is read for vectors.
                'metadata_delivery' => MetadataDelivery::Manual,
                'metadata_rules' => [
                    'iptc_read_for' => ['vector'],
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'dreamstime',
                'name' => 'Dreamstime',
                'logo' => 'marketplaces/dreamstime.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'upload.dreamstime.com',
                ],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    'csv_upload' => 'ftp',
                    // Model/property releases go in a separate CSV.
                    'releases_csv' => true,
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'depositphotos',
                'name' => 'Depositphotos',
                'logo' => 'marketplaces/depositphotos.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [],
                // No data on how metadata is delivered yet.
                'metadata_delivery' => null,
                'metadata_rules' => [
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => '123rf',
                'name' => '123RF',
                'logo' => 'marketplaces/123rf.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'ftp.123rf.com',
                    'quota_gb' => 4,
                    // Video and audio use separate FTP servers.
                    'separate_hosts_for' => ['video', 'audio'],
                ],
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    'csv_max_size_mb' => 2,
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'pond5',
                'name' => 'Pond5',
                'logo' => 'marketplaces/pond5.svg',
                'is_active' => true,
                'supported_types' => [MediaType::Video, MediaType::Photo],
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'ftp.pond5.com',
                    // FTP uses a separate password, not the account password.
                    'separate_password' => true,
                ],
                // Metadata is added in the portal after files are processed.
                'metadata_delivery' => MetadataDelivery::Manual,
                'metadata_rules' => [
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'magnific',
                'name' => 'Magnific (Freepik)',
                'logo' => 'marketplaces/magnific.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [],
                // Title and keywords must be embedded in the JPG, or sent as CSV.
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    'csv_supported' => true,
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'vecteezy',
                'name' => 'Vecteezy',
                'logo' => 'marketplaces/vecteezy.svg',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    'csv_fields' => ['Filename', 'Title', 'Description', 'Keywords'],
                    // CSV can be uploaded over FTP together with the files.
                    'csv_upload' => 'ftp',
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'canva',
                'name' => 'Canva Creators',
                'logo' => 'marketplaces/canva.svg',
                // Web portal only; contributor program is by application.
                'is_active' => false,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Web,
                'upload_defaults' => [],
                'metadata_delivery' => MetadataDelivery::Manual,
                'metadata_rules' => [
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
        ];
    }
}
