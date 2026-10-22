<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Gives every web login, including "remember me" logins, an API token cookie.
 * Logins outside an HTTP request with a session (console, queue, seeders) get no token.
 */
class LoginListener
{
    public function __construct(protected ApiTokenCookie $tokens, protected Request $request) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        Cookie::queue($this->tokens->issue($event->user, $this->request));
    }
}
