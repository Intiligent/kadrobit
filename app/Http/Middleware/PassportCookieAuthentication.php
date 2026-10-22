<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the browser authenticate API requests with the HttpOnly token cookie:
 * the cookie becomes a Bearer token unless the request already carries one.
 *
 * Changing requests authenticated by the cookie must carry X-Requested-With: XMLHttpRequest
 * (the API client always sends it). SameSite only separates sites, not subdomains, and a plain
 * cross-site form cannot set custom headers, while a cross-origin fetch with one is stopped by CORS.
 */
class PassportCookieAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(ApiTokenCookie::COOKIE);

        if (! $request->bearerToken() && is_string($token) && ($request->isMethodSafe() || $request->ajax())) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
