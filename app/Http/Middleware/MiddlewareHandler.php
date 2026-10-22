<?php

namespace App\Http\Middleware;

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
            EnsureIdempotency::class,
        ]);

        $middleware->api(append: [
            EnsureIdempotency::class,
        ]);

        // Idempotency keys are scoped to the user, so the user must be resolved first.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureIdempotency::class);

        return $middleware;
    }
}
