<?php

namespace App\Http\Resources\Settings;

use App\Enums\MediaType;
use App\Enums\UploadProtocol;
use App\Models\Marketplace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/**
 * A marketplace in the "connect" catalog, with what the form needs to know about it.
 *
 * @mixin Marketplace
 */
class MarketplaceCatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $note = "settings.marketplaces.notes.{$this->code}";

        return [
            'code' => $this->code,
            'name' => $this->name,
            'logo_url' => $this->logo_url,
            'upload_protocol' => $this->upload_protocol->value,
            'default_host' => $this->upload_defaults['host'] ?? null,
            'separate_password' => (bool) ($this->upload_defaults['separate_password'] ?? false),
            'supported_types' => $this->supported_types->map(fn (MediaType $type) => $type->value)->values()->all(),
            'connectable' => $this->is_active && in_array($this->upload_protocol, UploadProtocol::fileTransfer(), true),
            'note' => Lang::has($note) ? __($note) : null,
        ];
    }
}
