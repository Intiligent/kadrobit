<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Development-only routes answer 404 outside the local environment.
 */
class EnsureLocalEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->isLocal(), 404);

        return $next($request);
    }
}
