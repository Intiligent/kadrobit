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
 * contributor_url: the contributor's own area on the marketplace site, linked from the settings page.
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
                'contributor_url' => 'https://contributor.stock.adobe.com/',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Sftp,
                'upload_defaults' => [
                    // Shown in the contributor portal (Upload → Learn More), not in the public docs.
                    'host' => 'sftp.contributor.adobestock.com',
                    'port' => 22,
                    // The SFTP login is the contributor number: the form asks for it once.
                    'login_is_contributor_id' => true,
                    // SFTP is available only for "qualified" contributor accounts.
                    'requires_approval' => true,
                ],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title only, up to 70 characters (helpx.adobe.com, "CSV requirements", June 2026; the older sample CSV still says 200).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => 70],
                    ],
                    'keywords_max' => 49,
                    // CSV "Category" column: one category number, optional.
                    'categories_min' => 0,
                    'categories_max' => 1,
                    'csv_max_rows' => 5000,
                    'csv_upload' => 'web',
                    'submit' => 'web',
                ],
                // Per media type. Photos and illustrations: 4 to 100 MP, up to 45 MB (helpx.adobe.com,
                // "Technical and legal requirements for photo submission"). The portal rejects smaller
                // files with "resolution is too small".
                'file_rules' => [
                    'photo' => ['min_megapixels' => 4, 'max_megapixels' => 100, 'max_file_mb' => 45],
                    'illustration' => ['min_megapixels' => 4, 'max_megapixels' => 100, 'max_file_mb' => 45],
                ],
            ],
            [
                'code' => 'shutterstock',
                'name' => 'Shutterstock',
                'logo' => 'marketplaces/shutterstock.svg',
                'contributor_url' => 'https://submit.shutterstock.com/',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftps,
                'upload_defaults' => [
                    'host' => 'ftps.shutterstock.com',
                ],
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // One text field, Description, up to 2048 characters (submit.shutterstock.com help, "Contextual metadata"); the title is not sent.
                    'text_fields' => [
                        ['field' => 'description', 'name' => 'Description', 'max' => 2048],
                    ],
                    // One text field, Description: the work's title is not sent.
                    'required' => ['description'],
                    // Contributors may not submit generative AI content.
                    'accepts_ai_generated' => false,
                    'keywords_min' => 7,
                    'keywords_max' => 50,
                    'categories_min' => 1,
                    'categories_max' => 2,
                    'csv_fields' => ['Filename', 'Description', 'Keywords', 'Categories', 'Illustration', 'Mature Content', 'Editorial'],
                    // CSV is accepted only via the website, not over FTPS.
                    'csv_upload' => 'web',
                    'submit' => 'web',
                ],
                // Per media type (submit.shutterstock.com, "What are the technical requirements for images"):
                // photos JPEG or TIFF from 4 MP, vectors EPS (Illustrator 8/10) up to 100 MB. Over FTPS a JPEG
                // may be up to 50 MB and a TIFF up to 4 GB, so the size is not checked by format here.
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'TIFF'], 'min_megapixels' => 4],
                    'illustration' => ['formats' => ['JPEG', 'TIFF'], 'min_megapixels' => 4],
                    'vector' => ['formats' => ['EPS'], 'max_file_mb' => 100],
                    'video' => ['formats' => ['MP4', 'MOV']],
                ],
            ],
            [
                'code' => 'istock',
                'name' => 'iStock / Getty Images',
                'logo' => 'marketplaces/istock.svg',
                'contributor_url' => 'https://esp.gettyimages.com/',
                // No FTP and no public API: web portal (ESP) only.
                'is_active' => false,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Web,
                'upload_defaults' => [],
                'metadata_delivery' => MetadataDelivery::Manual,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title and Description in ESP; no official limits found (the guides need an ESP sign-in).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => null],
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'alamy',
                'name' => 'Alamy',
                'logo' => 'marketplaces/alamy.svg',
                'contributor_url' => 'https://www.alamy.com/contributor/',
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
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Caption up to 128 characters, searchable; Description up to 2000, not searchable (Alamy's PDF guide "Captions and keywords", may be dated).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Caption', 'max' => 128],
                        ['field' => 'description', 'name' => 'Description', 'max' => 2000],
                    ],
                    'iptc_read_for' => ['vector'],
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'dreamstime',
                'name' => 'Dreamstime',
                'logo' => 'marketplaces/dreamstime.svg',
                'contributor_url' => 'https://www.dreamstime.com/upload',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'upload.dreamstime.com',
                ],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title and Description, English only; no official limits found (dreamstime.com/upload/help-instructions).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => null],
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
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
                'contributor_url' => 'https://depositphotos.com/contributors.html',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [],
                // No data on how metadata is delivered yet.
                'metadata_delivery' => null,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // One text field, Description, up to 250 characters with spaces (blog.depositphotos.com, keywording guide).
                    'text_fields' => [
                        ['field' => 'description', 'name' => 'Description', 'max' => 250],
                    ],
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => '123rf',
                'name' => '123RF',
                'logo' => 'marketplaces/123rf.svg',
                'contributor_url' => 'https://www.123rf.com/sellimages/',
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
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Description, required; no official limit found (123rf.com/submit/photographersfaq.php).
                    'text_fields' => [
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
                    'csv_max_size_mb' => 2,
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'pond5',
                'name' => 'Pond5',
                'logo' => 'marketplaces/pond5.svg',
                'contributor_url' => 'https://contributor.pond5.com/',
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
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title up to 80 characters, Description up to 500 (contributor.pond5.com, "Preparing your files").
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => 80],
                        ['field' => 'description', 'name' => 'Description', 'max' => 500],
                    ],
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'magnific',
                'name' => 'Magnific (Freepik)',
                'logo' => 'marketplaces/magnific.svg',
                'contributor_url' => 'https://contributor.magnific.com/',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [],
                // Title and keywords must be embedded in the JPG, or sent as CSV.
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title only, one sentence, 35 to 100 characters recommended (magnific.com/ai/contributors, metadata guidelines).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => 100],
                    ],
                    'csv_supported' => true,
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
            [
                'code' => 'vecteezy',
                'name' => 'Vecteezy',
                'logo' => 'marketplaces/vecteezy.svg',
                'contributor_url' => 'https://contributors.vecteezy.com/',
                'is_active' => true,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title under 100 characters; Description optional, no limit found (eezycontributors.zendesk.com).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => 99],
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
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
                'contributor_url' => 'https://www.canva.com/creators/',
                // Web portal only; contributor program is by application.
                'is_active' => false,
                'supported_types' => $all,
                'upload_protocol' => UploadProtocol::Web,
                'upload_defaults' => [],
                'metadata_delivery' => MetadataDelivery::Manual,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title (unique, English) and an optional Description; no official limits found (canva.cn/en/help/metadata-cn).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => null],
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
                    'submit' => 'web',
                ],
                'file_rules' => [],
            ],
        ];
    }
}
