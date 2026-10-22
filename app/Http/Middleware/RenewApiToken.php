<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the API token alive for as long as the web session is: a logged-in user whose token
 * cookie is missing, foreign or about to expire gets a new one. Without it, API calls would
 * start failing while the session is still valid.
 */
class RenewApiToken
{
    /** Routes that issue or revoke the token themselves. */
    protected const SKIP_ROUTES = ['logout', 'auth.token'];

    public function __construct(protected ApiTokenCookie $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Resolving the user may log in via "remember me", which issues a token through LoginListener.
        $user = Auth::guard('web')->user();

        if ($user instanceof User
            && ! $request->routeIs(...self::SKIP_ROUTES)
            && $this->tokens->needsRenewal($request)
            && $this->tokens->claimRenewal($request)
        ) {
            Cookie::queue($this->tokens->issue($user, $request));
        }

        return $next($request);
    }
}
