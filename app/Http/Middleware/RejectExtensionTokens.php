<?php

namespace App\Http\Middleware;

use App\Services\Extension\ExtensionTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the browser extension's tokens to the extension API: the rest of the API answers them 403.
 * Runs after auth:api, which resolves the token.
 */
class RejectExtensionTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(ExtensionTokens::isExtensionToken($request->user()?->token()), 403);

        return $next($request);
    }
}
