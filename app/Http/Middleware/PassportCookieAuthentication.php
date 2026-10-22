<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the browser authenticate API requests with the HttpOnly token cookie:
 * the cookie becomes a Bearer token unless the request already carries one.
 */
class PassportCookieAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken() && $token = $request->cookie(ApiTokenCookie::COOKIE)) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
