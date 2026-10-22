<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Configuration\Middleware as BaseMiddleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

class MiddlewareHandler
{
    public function __invoke(BaseMiddleware $middleware): BaseMiddleware
    {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RenewApiToken::class,
            EnsureIdempotency::class,
        ]);

        // The browser authenticates API requests with the HttpOnly token cookie.
        $middleware->api(prepend: [
            PassportCookieAuthentication::class,
        ], append: [
            EnsureIdempotency::class,
        ]);

        // Passport tokens are signed JWTs; the cookie is HttpOnly and needs no extra encryption.
        $middleware->encryptCookies(except: [
            ApiTokenCookie::COOKIE,
        ]);

        $middleware->alias([
            'local.only' => EnsureLocalEnvironment::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));

        // Idempotency keys are scoped to the user, so the user must be resolved first.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureIdempotency::class);

        return $middleware;
    }
}
