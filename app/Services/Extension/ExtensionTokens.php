<?php

namespace App\Services\Extension;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenResult;
use Laravel\Passport\Token;

/**
 * Tokens of the browser extension (docs/browser-extension.md). Both kinds carry the extension scope:
 * those the extension gets through OAuth with PKCE, and personal ones issued in Settings → Extension
 * and pasted into it by hand when the OAuth window cannot open.
 */
class ExtensionTokens
{
    /** Name of the personal tokens issued in Settings → Extension. */
    public const PERSONAL_NAME = 'extension';

    /**
     * A personal token for the extension. Passport gives every personal token the web session's lifetime
     * and reads it on each issue, so it is lengthened for this one token only.
     */
    public function issue(User $user): PersonalAccessTokenResult
    {
        $lifetime = Passport::personalAccessTokensExpireIn();
        Passport::personalAccessTokensExpireIn(now()->addDays(config('extension.token_days')));

        try {
            return $user->createToken(self::PERSONAL_NAME, [config('extension.scope')]);
        } finally {
            Passport::personalAccessTokensExpireIn($lifetime);
        }
    }

    /**
     * The user's live extension tokens, newest first. An OAuth connection has one: a refresh replaces it.
     *
     * @return Collection<int, Token>
     */
    public function connections(User $user): Collection
    {
        return self::live($user->getKey())->with('client', 'refreshToken')->latest()->get();
    }

    /**
     * The user has an extension connected, memoized for the request: the library asks it for every listing.
     */
    public static function connected(int $userId): bool
    {
        return once(fn () => self::live($userId)->exists());
    }

    /**
     * The user's live extension tokens.
     */
    protected static function live(int $userId): Builder
    {
        return Passport::token()->newQuery()
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->where(fn ($query) => $query
                ->where('client_id', config('extension.client_id'))
                ->orWhere('scopes', 'like', '%"'.config('extension.scope').'"%'))
            ->where(fn ($query) => $query
                ->where('expires_at', '>', now())
                // An expired OAuth access token still connects the extension while its refresh token lives.
                ->orWhereHas('refreshToken', fn ($refresh) => $refresh->where('revoked', false)->where('expires_at', '>', now())));
    }

    /**
     * Disconnects the extension at once: the token and its refresh token stop working.
     */
    public function revoke(Token $token): void
    {
        $token->revoke();
        $token->refreshToken?->revoke();
    }

    /**
     * The token of the current request belongs to the extension: issued to its OAuth client (whatever scopes were
     * asked for) or carrying the extension scope (a personal token).
     */
    public static function isExtensionToken(?ScopeAuthorizable $token): bool
    {
        return $token !== null
            && ($token->oauth_client_id === config('extension.client_id') || $token->can(config('extension.scope')));
    }

    /**
     * Revokes all the user's extension tokens and their refresh tokens, e.g. after a password reset.
     */
    public function revokeAll(User $user): void
    {
        $this->connections($user)->each(fn (Token $token) => $this->revoke($token));
    }
}
