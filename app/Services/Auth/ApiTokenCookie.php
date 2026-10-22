<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Issues the Passport personal access token of a web session and carries it in an HttpOnly cookie.
 *
 * The token id and expiry are kept in the session, so checking whether the token needs renewal
 * costs no database query. Each session has its own token: logging out revokes only that one.
 */
class ApiTokenCookie
{
    public const COOKIE = 'api_token';

    public const TOKEN_NAME = 'web';

    protected const SESSION_TOKEN_ID = 'api_token.id';

    protected const SESSION_EXPIRES_AT = 'api_token.expires_at';

    /** The token is renewed this many seconds before it expires. */
    public const RENEW_BEFORE = 300;

    /**
     * Revokes the session's previous token, issues a new one and returns its cookie.
     */
    public function issue(User $user, Session $session): Cookie
    {
        $this->revoke($session);

        $result = $user->createToken(self::TOKEN_NAME);
        $expiresAt = now()->addSeconds($result->expiresIn);

        $session->put([
            self::SESSION_TOKEN_ID => $result->accessTokenId,
            self::SESSION_EXPIRES_AT => $expiresAt->getTimestamp(),
        ]);

        return $this->make($result->accessToken, (int) ceil(now()->diffInMinutes($expiresAt)));
    }

    /**
     * True when the request has no token cookie, the session knows no token, or it is about to expire.
     */
    public function needsRenewal(Request $request): bool
    {
        $expiresAt = $request->session()->get(self::SESSION_EXPIRES_AT);

        return ! $request->cookies->has(self::COOKIE)
            || $expiresAt === null
            || now()->getTimestamp() >= $expiresAt - self::RENEW_BEFORE;
    }

    /**
     * Revokes the token issued to this session, if any.
     */
    public function revoke(Session $session): void
    {
        if ($tokenId = $session->pull(self::SESSION_TOKEN_ID)) {
            Passport::token()->newQuery()->whereKey($tokenId)->update(['revoked' => true]);
        }

        $session->forget(self::SESSION_EXPIRES_AT);
    }

    public function forget(): Cookie
    {
        return $this->make(null, -2628000);
    }

    protected function make(?string $value, int $minutes): Cookie
    {
        // Lax at least: the cookie authenticates API requests, so it must not ride along cross-site POSTs.
        $sameSite = strtolower((string) config('session.same_site')) === 'strict' ? 'strict' : 'lax';

        return cookie(
            self::COOKIE,
            $value,
            $minutes,
            '/',
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            $sameSite,
        );
    }
}
