<?php

namespace App\Listeners;

use App\Services\Auth\ApiTokenCookie;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;

/**
 * Revokes the API token of the session being logged out; other devices keep theirs.
 */
class LogoutListener
{
    public function __construct(protected ApiTokenCookie $tokens) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $this->tokens->revoke(request()->session());

        Cookie::queue($this->tokens->forget());
    }
}
