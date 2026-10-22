<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;

/**
 * Whoever knew the old password must not stay logged in: a reset revokes the user's API tokens
 * and ends their stored web sessions on every device. "Remember me" logins end with the new
 * remember token set by the reset itself.
 */
class PasswordResetListener
{
    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->tokens()->where('revoked', false)->update(['revoked' => true]);

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $event->user->getKey())
                ->delete();
        }
    }
}
