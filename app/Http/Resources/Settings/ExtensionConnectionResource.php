<?php

namespace App\Http\Resources\Settings;

use App\Services\Extension\ExtensionTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Passport\Token;

/**
 * A connected browser extension for Settings → Extension. Needs the token's client and refresh token.
 *
 * @mixin Token
 */
class ExtensionConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $personal = $this->name === ExtensionTokens::PERSONAL_NAME;

        return [
            'id' => $this->id,
            // oauth: signed in from the extension; personal: a token from this page pasted into it.
            'kind' => $personal ? 'personal' : 'oauth',
            // When the current token was issued: an OAuth connection renews it while the extension is in use.
            'issued_at' => $this->created_at?->toIso8601String(),
            // Unused this long, the extension has to sign in again.
            'expires_at' => ($personal ? $this->expires_at : ($this->refreshToken?->expires_at ?? $this->expires_at))?->toIso8601String(),
        ];
    }
}
