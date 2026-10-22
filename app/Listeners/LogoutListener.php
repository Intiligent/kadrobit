<?php

namespace App\Listeners;

use App\Services\Auth\ApiTokenCookie;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Revokes the API token of the session being logged out; other devices keep theirs.
 */
class LogoutListener
{
    public function __construct(protected ApiTokenCookie $tokens, protected Request $request) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web' || ! $this->request->hasSession()) {
            return;
        }

        $this->tokens->revoke($this->request);

        Cookie::queue($this->tokens->forget());
    }
}
