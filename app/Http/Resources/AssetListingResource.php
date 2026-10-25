<?php

namespace App\Http\Resources;

use App\Models\AssetListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A work's delivery to one marketplace account. "outdated" needs the listing's asset and account.marketplace.
 *
 * @mixin AssetListing
 */
class AssetListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->marketplace_account_id,
            'status' => $this->status->value,
            // Checks that did not pass when it was queued: [{key, params}], keys under library.checks.
            'warnings' => $this->warnings,
            // auth | connect | other | account | unsupported | file
            'error' => $this->error,
            // Sent, and the work was edited since: the marketplace still has the old metadata.
            'outdated' => $this->isOutdated(),
            'remote_filename' => $this->remote_filename,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
