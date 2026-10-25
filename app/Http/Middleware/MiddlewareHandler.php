<?php

namespace App\Http\Middleware;

use App\Services\Auth\ApiTokenCookie;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Configuration\Middleware as BaseMiddleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;

class MiddlewareHandler
{
    public function __invoke(BaseMiddleware $middleware): BaseMiddleware
    {
        $middleware->web(append: [
            // Moved right after StartSession by the priority list below.
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
            // The API has no session: the user is read from the token guard, before auth:api runs.
            SetLocale::class.':api',
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

        // The language is set as soon as the session is there, so errors of the middleware that follow
        // (an expired CSRF token, a throttled form) and the shared Inertia `locale` prop are in the user's language.
        $middleware->appendToPriorityList(StartSession::class, SetLocale::class);

        return $middleware;
    }
}
