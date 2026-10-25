<?php

namespace App\Http\Resources;

use App\Enums\MediaType;
use App\Models\MarketplaceAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected account with the marketplace rules the info panel checks metadata against.
 * Credentials are never included.
 *
 * @mixin MarketplaceAccount
 */
class MarketplaceAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $marketplace = $this->marketplace;

        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status->value,
            'marketplace' => [
                'code' => $marketplace->code,
                'name' => $marketplace->name,
                'logo_url' => $marketplace->logo_url,
                'upload_protocol' => $marketplace->upload_protocol->value,
                'supported_types' => $marketplace->supported_types->map(fn (MediaType $type) => $type->value)->values()->all(),
                'metadata_delivery' => $marketplace->metadata_delivery?->value,
                'metadata_rules' => (object) $marketplace->metadata_rules,
                'file_rules' => (object) $marketplace->file_rules,
            ],
        ];
    }
}
