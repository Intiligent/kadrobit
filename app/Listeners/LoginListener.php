<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;

/**
 * Gives every web login, including "remember me" logins, an API token cookie.
 */
class LoginListener
{
    public function __construct(protected ApiTokenCookie $tokens) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        Cookie::queue($this->tokens->issue($event->user, request()->session()));
    }
}
