<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use App\Services\Extension\ExtensionTokens;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Whoever knew the old password must not keep access. The reset itself sets a new remember token,
 * which ends "remember me" logins; AuthenticateSession logs out every session whose stored password
 * hash no longer matches, and LogoutListener then revokes that session's token. Here the web-session
 * API tokens are revoked at once, so API calls stop before those sessions make another web request.
 * The browser extension's tokens go too: whoever knew the password could have connected one, for up to a year.
 * Other token kinds (e.g. integrations) are separate credentials and stay valid.
 */
class PasswordResetListener
{
    public function __construct(protected ExtensionTokens $extensionTokens) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->tokens()
            ->where('name', ApiTokenCookie::TOKEN_NAME)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        $this->extensionTokens->revokeAll($event->user);
    }
}
