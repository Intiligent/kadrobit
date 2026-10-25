<?php

use App\Exceptions\ExceptionHandler;
use App\Http\Middleware\MiddlewareHandler;
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private channels are authorized with the web session (POST /broadcasting/auth).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth']])
    ->withMiddleware(new MiddlewareHandler)
    ->withExceptions(new ExceptionHandler)
    ->create();
