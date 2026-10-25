<?php

namespace App\Services\Marketplaces;

use App\Enums\AssetFileRole;
use App\Enums\MarketplaceAccountStatus;
use App\Enums\MetadataDelivery;
use App\Models\Asset;
use App\Models\MarketplaceAccount;

/**
 * Checks a work against a marketplace account before it is queued: the server side of
 * resources/js/utils/marketplaceRules.js, with the same rules and translation keys (library.checks.*).
 *
 * A blocker means the upload cannot happen at all (account off, type not accepted). An error is a file the marketplace
 * rejects on upload (resolution, size): editing metadata cannot fix it, so the pair is not sent. Everything else is a
 * warning: the work is still sent, since the user submits it in the marketplace's portal and can fix it there.
 */
class ListingChecks
{
    /** Format names used in marketplaces.file_rules.*.formats, by MIME type of the original. */
    public const FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/tiff' => 'TIFF',
        'image/svg+xml' => 'SVG',
        'application/postscript' => 'EPS',
        'image/x-eps' => 'EPS',
        'video/mp4' => 'MP4',
        'video/quicktime' => 'MOV',
    ];

    /** Keys of blockers, under library.checks. */
    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const DISABLED = 'disabled';

    public const UNSUPPORTED_TYPE = 'unsupported_type';

    /**
     * Whether the account's marketplace never takes the work: a type it does not accept or a file outside its limits.
     * The account's own state does not matter: turned off or signed out, it may take the work later.
     * Needs the account's marketplace and the work's files.
     */
    public function rejects(Asset $asset, MarketplaceAccount $account): bool
    {
        $marketplace = $account->marketplace;

        return ! $marketplace->supports($asset->type)
            || $this->fileChecks($asset, $marketplace->file_rules[$asset->type->value] ?? null)[1] !== [];
    }

    /**
     * Needs the account's marketplace and the work's files.
     */
    public function check(Asset $asset, MarketplaceAccount $account): ListingCheck
    {
        $marketplace = $account->marketplace;
        $rules = $marketplace->metadata_rules ?? [];

        $blocker = $this->accountBlocker($account) ?? (! $marketplace->supports($asset->type) ? self::UNSUPPORTED_TYPE : null);

        if ($blocker) {
            return new ListingCheck($blocker);
        }

        [$warnings, $errors] = $this->fileChecks($asset, $marketplace->file_rules[$asset->type->value] ?? null);

        if ($asset->is_ai_generated && ($rules['accepts_ai_generated'] ?? true) === false) {
            $warnings[] = $this->issue('ai_not_accepted');
        }

        foreach ($rules['required'] ?? ['title'] as $field) {
            if (blank($asset->{$field})) {
                $warnings[] = $this->issue("{$field}_required");
            }
        }

        // Metadata entered in the marketplace's own portal: nothing more to check on our side.
        if ($marketplace->metadata_delivery === MetadataDelivery::Manual) {
            return new ListingCheck(warnings: $warnings, errors: $errors);
        }

        $count = count($asset->keywords ?? []);
        $min = $rules['keywords_min'] ?? null;
        $max = $rules['keywords_max'] ?? null;

        if (! $count) {
            $warnings[] = $this->issue('keywords_required');
        } elseif ($min && $count < $min) {
            $warnings[] = $this->issue('keywords_min', ['min' => $min, 'count' => $count]);
        } elseif ($max && $count > $max) {
            $warnings[] = $this->issue('keywords_max', ['max' => $max, 'count' => $count]);
        }

        if (($rules['categories_max'] ?? null) && count($asset->categories[$marketplace->code] ?? []) < ($rules['categories_min'] ?? 0)) {
            $warnings[] = $this->issue('categories_min');
        }

        return new ListingCheck(warnings: $warnings, errors: $errors);
    }

    /**
     * Why the account takes no work at all: turned off, or its last sign-in was rejected.
     */
    public function accountBlocker(MarketplaceAccount $account): ?string
    {
        return match ($account->status) {
            MarketplaceAccountStatus::InvalidCredentials => self::INVALID_CREDENTIALS,
            MarketplaceAccountStatus::Disabled => self::DISABLED,
            default => null,
        };
    }

    /**
     * Format, resolution and size of the original, from marketplaces.file_rules for the work's type.
     * A format is a warning (Kadrobit may convert the file one day); resolution and size are errors.
     *
     * @return array{0: list<array{key: string, params: array<string, mixed>}>, 1: list<array{key: string, params: array<string, mixed>}>} Warnings and errors
     */
    protected function fileChecks(Asset $asset, ?array $rules): array
    {
        if (! $rules) {
            return [[], []];
        }

        $warnings = [];
        $errors = [];
        $original = $asset->file(AssetFileRole::Original);
        $format = self::FORMATS[$original?->mime] ?? null;

        if ($format && ($rules['formats'] ?? null) && ! in_array($format, $rules['formats'], true)) {
            $warnings[] = $this->issue('format', ['format' => $format, 'formats' => implode(', ', $rules['formats'])]);
        }

        $width = $asset->width;
        $height = $asset->height;

        if ($width && $height) {
            $pixels = $width * $height;
            // Rounded down like the frontend's megapixels(), so a file just under the minimum never reads as "4.0 MP".
            $params = ['width' => $width, 'height' => $height, 'mp' => floor($pixels / 100_000) / 10];

            if (($rules['min_megapixels'] ?? null) && $pixels < $rules['min_megapixels'] * 1_000_000) {
                $errors[] = $this->issue('resolution_min', [...$params, 'min' => $rules['min_megapixels']]);
            } elseif (($rules['max_megapixels'] ?? null) && $pixels > $rules['max_megapixels'] * 1_000_000) {
                $errors[] = $this->issue('resolution_max', [...$params, 'max' => $rules['max_megapixels']]);
            }
        }

        $size = $original?->size_bytes;

        if ($size && ($rules['max_file_mb'] ?? null) && $size > $rules['max_file_mb'] * 1024 * 1024) {
            $errors[] = $this->issue('file_too_large', ['size' => round($size / 104_857.6) / 10, 'max' => $rules['max_file_mb']]);
        }

        return [$warnings, $errors];
    }

    /**
     * @return array{key: string, params: array<string, mixed>}
     */
    protected function issue(string $key, array $params = []): array
    {
        return ['key' => $key, 'params' => $params];
    }
}
