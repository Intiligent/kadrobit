<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Issues the Passport personal access token of a web session and carries it in an HttpOnly cookie.
 *
 * The token id, its hash and expiry are kept in the session, so checking whether the cookie holds
 * this session's live token costs no database query. Each session has its own token: logging out
 * revokes only that one. The token lives as long as the session (see AppServiceProvider).
 */
class ApiTokenCookie
{
    public const COOKIE = 'api_token';

    public const TOKEN_NAME = 'web';

    protected const SESSION_TOKEN_ID = 'api_token.id';

    protected const SESSION_TOKEN_HASH = 'api_token.hash';

    protected const SESSION_EXPIRES_AT = 'api_token.expires_at';

    /** Request attribute set once a token was issued in this request. */
    protected const ISSUED_ATTRIBUTE = 'api_token.issued';

    /** The token is renewed this many seconds before it expires. */
    public const RENEW_BEFORE = 300;

    /** How long a renewal claims the session, so parallel requests do not renew it again. */
    protected const RENEW_LOCK_SECONDS = 10;

    /**
     * Revokes the session's previous token, issues a new one and returns its cookie.
     */
    public function issue(User $user, Request $request): Cookie
    {
        $session = $request->session();

        $this->revoke($request);

        $result = $user->createToken(self::TOKEN_NAME);
        $expiresAt = now()->addSeconds($result->expiresIn);

        $session->put([
            self::SESSION_TOKEN_ID => $result->accessTokenId,
            self::SESSION_TOKEN_HASH => $this->hash($result->accessToken),
            self::SESSION_EXPIRES_AT => $expiresAt->getTimestamp(),
        ]);

        $request->attributes->set(self::ISSUED_ATTRIBUTE, true);

        return $this->make($result->accessToken, (int) ceil(now()->diffInMinutes($expiresAt)));
    }

    /**
     * True when the request carries no cookie, a cookie that is not this session's token,
     * or a token about to expire. False once a token was issued in this request.
     */
    public function needsRenewal(Request $request): bool
    {
        if ($request->attributes->get(self::ISSUED_ATTRIBUTE)) {
            return false;
        }

        $session = $request->session();
        $cookie = $request->cookies->get(self::COOKIE);
        $hash = $session->get(self::SESSION_TOKEN_HASH);
        $expiresAt = $session->get(self::SESSION_EXPIRES_AT);

        return ! is_string($cookie)
            || ! is_string($hash)
            || ! hash_equals($hash, $this->hash($cookie))
            || $expiresAt === null
            || now()->getTimestamp() >= $expiresAt - self::RENEW_BEFORE;
    }

    /**
     * Claims the renewal of this session for a few seconds. Parallel requests read the same session
     * before either saves it; without the claim each would issue its own token and only one would
     * end up in the session, leaving the other unrevoked on logout.
     */
    public function claimRenewal(Request $request): bool
    {
        return Cache::add('api_token.renewing:'.$request->session()->getId(), true, self::RENEW_LOCK_SECONDS);
    }

    /**
     * Revokes the token issued to this session, if any.
     */
    public function revoke(Request $request): void
    {
        $session = $request->session();

        if ($tokenId = $session->pull(self::SESSION_TOKEN_ID)) {
            Passport::token()->newQuery()->whereKey($tokenId)->update(['revoked' => true]);
        }

        $session->forget([self::SESSION_TOKEN_HASH, self::SESSION_EXPIRES_AT]);
    }

    public function forget(): Cookie
    {
        return $this->make(null, -2628000);
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
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
