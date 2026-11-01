<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * A browser notification the push service did not take (docs/notifications.md). The channel only drops expired
 * subscriptions and says nothing else, so a wrong VAPID key or an unreachable service would go unnoticed.
 */
class PushFailedListener
{
    public function handle(NotificationFailed $event): void
    {
        Log::warning('Web push failed', [
            'subscription' => $event->subscription->getKey(),
            'status' => $event->report->getResponse()?->getStatusCode(),
            'reason' => $event->report->getReason(),
            'expired' => $event->report->isSubscriptionExpired(),
        ]);
    }
}
