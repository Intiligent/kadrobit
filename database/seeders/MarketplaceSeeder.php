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
                'name' => 'iStock',
                'logo' => 'marketplaces/istock.svg',
                'contributor_url' => 'https://www.istockphoto.com/',
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
                'code' => 'getty_images',
                'name' => 'Getty Images',
                'logo' => 'marketplaces/getty_images.svg',
                'contributor_url' => 'https://www.gettyimages.com/',
                // No FTP and no public API: web portal (ESP) only, shared with iStock.
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
                // Video is taken from partner agencies only (alamy.com/help/contributor-uploading).
                'supported_types' => [MediaType::Photo, MediaType::Vector],
                // Plain FTP: the server offers no AUTH TLS (FEAT), so the password goes in the clear.
                // The login is the account's email. Folders: Stock, Vectors (Live News and Archive stock need Alamy's consent).
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'upload.alamy.com',
                    'port' => 21,
                ],
                // Alamy reads the caption (IPTC description) and tags (keywords) from the JPEG (Alamy's FAQ
                // "Captions and keywords"); the rest is entered in Image Manager.
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Caption up to 128 characters, searchable; Description up to 2000, not searchable (Alamy's PDF guide "Captions and keywords", may be dated).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Caption', 'max' => 128],
                        ['field' => 'description', 'name' => 'Description', 'max' => 2000],
                    ],
                    // AI-generated images are not accepted.
                    'accepts_ai_generated' => false,
                    // Primary and optional Secondary category (MarketplaceCategorySeeder), chosen in Image Manager:
                    // the file carries none and there is no CSV, so they are not sent.
                    'categories_min' => 0,
                    'categories_max' => 2,
                    'submit' => 'web',
                ],
                // alamy.com/help/contributor-uploading: RGB baseline JPEG over 17 MB uncompressed (17 MiB / 3 bytes a pixel
                // ≈ 5.94 MP). PNG and TIFF are taken here because AlamyDelivery sends them as a JPEG copy. Vectors are EPS
                // only (not AI or SVG), with a JPEG of the same name for the metadata; no size limits are given.
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 5.94],
                    'vector' => ['formats' => ['EPS'], 'formats_strict' => true],
                ],
            ],
            [
                'code' => 'dreamstime',
                'name' => 'Dreamstime',
                'logo' => 'marketplaces/dreamstime.svg',
                'contributor_url' => 'https://www.dreamstime.com/upload',
                'is_active' => true,
                'supported_types' => $all,
                // The help page says FTP, but the server takes AUTH TLS (FEAT): FTPS keeps the password off the wire.
                'upload_protocol' => UploadProtocol::Ftps,
                // dreamstime.com/upload/help-ftp-upload: passive mode; JPEG images in the root folder, vectors and RAW
                // in /additional named like their JPEG (12345.eps for 12345.jpg), video in /video, releases in /modelrelease.
                'upload_defaults' => [
                    'host' => 'upload.dreamstime.com',
                    'port' => 21,
                    // The FTP login is the numeric user ID shown on that page, not the email; the password is the account's.
                    'login_is_contributor_id' => true,
                ],
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title and Description, English only; no official limits found (dreamstime.com/upload/help-instructions).
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => null],
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
                    // CSV "Category 1–3" columns: category numbers, optional (MarketplaceCategorySeeder).
                    'categories_min' => 0,
                    'categories_max' => 3,
                    // Uploaded to the FTP root once the files are processed (SendDreamstimeCsv);
                    // columns in docs/marketplaces/dreamstime/README.md.
                    'csv_upload' => 'ftp',
                    'csv_fields' => ['Filename', 'Image Name', 'Description', 'Category 1', 'Category 2', 'Category 3', 'keywords', 'Free', 'W-EL', 'P-EL', 'SR-EL', 'SR-Price', 'Editorial', 'MR doc Ids', 'Pr Docs'],
                    // Model/property releases go in a separate CSV.
                    'releases_csv' => true,
                    'submit' => 'web',
                ],
                // dreamstime.com/upload/help-ftp-upload: RGB JPEG from 3 MP, up to 1 GB. PNG and TIFF are taken here
                // because DreamstimeDelivery sends them as a JPEG copy. Vectors go with their JPEG, video as MOV (preferred).
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 3, 'max_file_mb' => 1024],
                    'illustration' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 3, 'max_file_mb' => 1024],
                    'vector' => ['formats' => ['EPS', 'SVG']],
                    'video' => ['formats' => ['MOV', 'MP4']],
                ],
            ],
            [
                'code' => 'depositphotos',
                'name' => 'Depositphotos',
                'logo' => 'marketplaces/depositphotos.svg',
                'contributor_url' => 'https://depositphotos.com/contributors.html',
                'is_active' => true,
                'supported_types' => $all,
                // depositphotos.com/faq/article/360010106174.html: ftp.depositphotos.com, the account's login and password.
                // Plain FTP: the server lists AUTH TLS in FEAT but answers it with "500 This security scheme is not
                // implemented" (30.10.2026), so the password goes in the clear.
                'upload_protocol' => UploadProtocol::Ftp,
                'upload_defaults' => [
                    'host' => 'ftp.depositphotos.com',
                    'port' => 21,
                ],
                // No data on how metadata is delivered yet.
                'metadata_delivery' => null,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // One text field, Description, up to 250 characters with spaces (blog.depositphotos.com, keywording guide).
                    'text_fields' => [
                        ['field' => 'description', 'name' => 'Description', 'max' => 250],
                    ],
                    // AI-generated files are not accepted (intellectual property concerns).
                    'accepts_ai_generated' => false,
                    'submit' => 'web',
                ],
                // Images (blog.depositphotos.com, "How to become a Depositphotos contributor"): JPEG or PNG in RGB,
                // from 3.8 MP (2400 x 1600), up to 50 MB. The RGB color space is not checked here.
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'PNG'], 'min_megapixels' => 3.8, 'max_file_mb' => 50],
                    'illustration' => ['formats' => ['JPEG', 'PNG'], 'min_megapixels' => 3.8, 'max_file_mb' => 50],
                ],
            ],
            [
                'code' => '123rf',
                'name' => '123RF',
                'logo' => 'marketplaces/123rf.svg',
                'contributor_url' => 'https://www.123rf.com/contributor/manage-content',
                'is_active' => true,
                // Video and audio go to separate FTP servers, not delivered yet.
                'supported_types' => [MediaType::Photo, MediaType::Illustration, MediaType::Vector],
                // The help page says FTP, but the server answers AUTH TLS with 234: FTPS keeps the password off the wire.
                // The login is the 123RF username, not the email; files go to the root.
                'upload_protocol' => UploadProtocol::Ftps,
                'upload_defaults' => [
                    'host' => 'ftp.123rf.com',
                    'port' => 21,
                    'quota_gb' => 4,
                    'separate_hosts_for' => ['video', 'audio'],
                ],
                // 123RF reads the caption (IPTC description) and keywords from the JPEG; empty fields stay empty
                // (blog.123rf.com/how-to-upload-photos-or-illustrations).
                'metadata_delivery' => MetadataDelivery::Iptc,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Description, required; no official limit found (123rf.com/submit/photographersfaq.php).
                    'text_fields' => [
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
                    // One text field, Description: the work's title is not sent.
                    'required' => ['description'],
                    // "Enter a minimum of 7 keywords"; 30–50 recommended, no maximum (123RF keywording guide).
                    'keywords_min' => 7,
                    // No categories: neither the help pages nor the CSV (oldfilename, 123rf_filename, description,
                    // keywords, country) have them (checked 31.10.2026).
                    'csv_max_size_mb' => 2,
                    // Upload Content → Upload Via FTP and accepting the terms on the website, then the drafts are submitted there.
                    'submit' => 'web',
                ],
                // blog.123rf.com/how-to-upload-photos-or-illustrations: JPEG from 6 MP, up to 30 MB, quality from 80 %. PNG and
                // TIFF are taken here because Rf123Delivery sends them as a JPEG copy; the 30 MB limit is not checked, since it
                // applies to the JPEG that goes, not to the original. Vectors are EPS with a JPG of the same name, at least
                // 1600 × 1600 (the JPG is not checked).
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 6],
                    'illustration' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 6],
                    'vector' => ['formats' => ['EPS'], 'formats_strict' => true],
                ],
            ],
            [
                'code' => 'pond5',
                'name' => 'Pond5',
                'logo' => 'marketplaces/pond5.svg',
                'contributor_url' => 'https://contributor.pond5.com/',
                'is_active' => true,
                'supported_types' => [MediaType::Video, MediaType::Photo],
                // The help page says FTP, but the server takes AUTH TLS (checked 02.11.2026 without signing in).
                'upload_protocol' => UploadProtocol::Ftps,
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
                // contributor.pond5.com, "Preparing your files": photos JPG, TIFF, PNG from 2 to 170 MP (at least
                // 1200×1200), sRGB or Adobe RGB, no CMYK; footage MOV or MP4. The FTP server takes files under 5 GB.
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 2, 'max_megapixels' => 170],
                    'video' => ['formats' => ['MOV', 'MP4'], 'formats_strict' => true, 'max_file_mb' => 5 * 1024],
                ],
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
                // Video goes over SFTP too, not delivered yet.
                'supported_types' => [MediaType::Photo, MediaType::Illustration, MediaType::Vector],
                // SFTP only since May 2022 (eezycontributors.zendesk.com, "SFTP Upload Process"); the server, login and
                // password are shown on the Upload page. The server answers SFTP on 22 (AWS Transfer), not FTP on 21
                // (checked 31.10.2026).
                'upload_protocol' => UploadProtocol::Sftp,
                'upload_defaults' => [
                    'host' => 'content-ftp.eezy.com',
                    'port' => 22,
                ],
                // A CSV uploaded with the files comes before the metadata embedded in them ("Upload Settings").
                'metadata_delivery' => MetadataDelivery::Csv,
                'metadata_rules' => [
                    // Text fields: which work field goes where, and the length the marketplace takes (null: not known).
                    // Title from 3 words, up to 200 characters; Description optional, no limit found ("Contributor Guide: Add Data").
                    'text_fields' => [
                        ['field' => 'title', 'name' => 'Title', 'max' => 200],
                        ['field' => 'description', 'name' => 'Description', 'max' => null],
                    ],
                    'keywords_min' => 5,
                    'keywords_max' => 50,
                    // Categories are picked on the Add Data page only; neither the CSV nor the file carries them.
                    // License: pro, or editorial for editorial works (VecteezyCsv).
                    'csv_fields' => ['Filename', 'Title', 'Description', 'Keywords', 'License'],
                    // One CSV per work, sent over SFTP ahead of its files (VecteezyDelivery).
                    'csv_upload' => 'sftp',
                    // With the contributor's Auto Submit setting valid works go to review on arrival; otherwise from Add Data.
                    'submit' => 'auto_submit',
                ],
                // "Content Guidelines - Photo": sRGB JPEG, 4 to 50 MP, up to 50 MB. PNG and TIFF are taken because
                // VecteezyDelivery sends them as a JPEG copy; the 50 MB limit is not checked, since it applies to the JPEG
                // that goes, not to the original. Vectors: EPS with a JPG of the same name ("FTP Upload Process").
                'file_rules' => [
                    'photo' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 4, 'max_megapixels' => 50],
                    'illustration' => ['formats' => ['JPEG', 'PNG', 'TIFF'], 'min_megapixels' => 4, 'max_megapixels' => 50],
                    'vector' => ['formats' => ['EPS'], 'formats_strict' => true],
                ],
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
