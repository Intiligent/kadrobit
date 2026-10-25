<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Configuration\Middleware as BaseMiddleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\AuthenticateSession;

class MiddlewareHandler
{
    public function __invoke(BaseMiddleware $middleware): BaseMiddleware
    {
        $middleware->web(append: [
            // Before Inertia: the shared `locale` prop is read when the middleware runs.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Ends any session whose user changed the password elsewhere (e.g. a reset), for every session driver.
            AuthenticateSession::class,
            RenewApiToken::class,
            EnsureIdempotency::class,
        ]);

        // The browser authenticates API requests with the HttpOnly token cookie.
        $middleware->api(prepend: [
            PassportCookieAuthentication::class,
        ], append: [
            SetLocale::class,
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

        // The API user's language comes from their settings, so the token must be checked first.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, SetLocale::class);

        return $middleware;
    }
}
