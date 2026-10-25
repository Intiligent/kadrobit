<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Any page that does not exist. Without this route the 404 is rendered before the web middleware runs,
 * with no session, so it could only be in English; here it gets the user's language.
 *
 * The route answers GET only, and it would hide the router's 405 for a URL that exists with another
 * method (GET /logout): that answer is kept.
 */
class FallbackController extends Controller
{
    public function __invoke(Request $request, Router $router): never
    {
        $methods = array_values(array_filter(
            array_diff(Router::$verbs, ['GET', 'HEAD']),
            fn (string $method) => collect($router->getRoutes()->get($method))
                ->contains(fn (Route $route) => ! $route->isFallback && $route->matches($request, false)),
        ));

        if ($methods) {
            throw new MethodNotAllowedHttpException($methods);
        }

        abort(404);
    }
}
