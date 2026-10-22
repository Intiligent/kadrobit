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
 * cookie is missing or about to expire gets a new one. Without it, API calls would start
 * failing after a day of activity while the session is still valid.
 */
class RenewApiToken
{
    public function __construct(protected ApiTokenCookie $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && $this->tokens->needsRenewal($request)) {
            Cookie::queue($this->tokens->issue($user, $request->session()));
        }

        return $next($request);
    }
}
