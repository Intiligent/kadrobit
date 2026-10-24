<?php

namespace App\Http\Resources\Settings;

use App\Models\MarketplaceAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected account for the settings page. Reads only plain columns: the password is never
 * decrypted here and never sent; `has_password` says whether one is saved.
 *
 * @mixin MarketplaceAccount
 */
class MarketplaceConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'contributor_id' => $this->contributor_id,
            'host' => $this->host,
            'login' => $this->login,
            'has_password' => $this->getRawOriginal('credentials') !== null,
            'status' => $this->status->value,
            'last_error' => $this->last_error,
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'credentials_changed_at' => $this->credentials_changed_at?->toIso8601String(),
            'marketplace' => [
                'code' => $this->marketplace->code,
                'name' => $this->marketplace->name,
                'logo_url' => $this->marketplace->logo_url,
                'upload_protocol' => $this->marketplace->upload_protocol->value,
            ],
        ];
    }
}
