<?php

namespace App\Support;

use App\Events\EntityChanged;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends EntityChanged without ever failing the caller: a real-time notice is a convenience, and the browser
 * catches up by itself after a reconnect or through its fallback poll. A job that has done its work must not
 * be retried because Pusher was slow or down.
 */
class Realtime
{
    public static function changed(User $user, string $type, string $id, string $reason): void
    {
        try {
            broadcast(new EntityChanged($user, $type, $id, $reason));
        } catch (Throwable $e) {
            Log::warning("Real-time notice {$type}:{$reason} for {$id} was not sent: {$e->getMessage()}");
        }
    }
}
