<?php

namespace App\Http\Resources\Extension;

use App\Models\AssetListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sent work as the browser extension needs it on the marketplace's site: the file name to find it by and the
 * metadata to fill in. Needs the listing's asset and account.marketplace.
 *
 * @mixin AssetListing
 */
class ExtensionListingResource extends JsonResource
{
    /**
     * Names of the marketplace's categories by external_id; an unknown one is sent as its id.
     *
     * @var array<string, string>
     */
    public array $categoryNames = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = AssetListing::snapshot($this->asset, $this->account->marketplace);

        return [
            'id' => $this->id,
            'account_id' => $this->marketplace_account_id,
            'remote_filename' => $this->remote_filename,
            'external_id' => $this->external_id,
            'external_url' => $this->external_url,
            'moderation_status' => $this->moderation_status?->value,
            'moderation_checked_at' => $this->moderation_checked_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            // The marketplace still has older metadata: the extension fills in these.
            'outdated' => $this->isOutdated(),
            'metadata' => [
                ...$metadata,
                'categories' => array_map(fn (string $id) => ['id' => $id, 'name' => $this->categoryNames[$id] ?? $id], $metadata['categories']),
            ],
            // Sent back with metadata_synced, so metadata edited meanwhile is not marked as on the marketplace.
            'metadata_hash' => self::hash($metadata),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata  AssetListing::snapshot()
     */
    public static function hash(array $metadata): string
    {
        return hash('sha256', json_encode($metadata));
    }
}
