<?php

namespace App\Notifications\Push;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Browser notification: the works on their way have all reached review on the marketplaces (ReviewSubmittedNotifier).
 * Sent in the user's language (User::preferredLocale()).
 */
class ReviewSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $count) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('notifications.review_submitted.title'))
            ->body(trans_choice('notifications.review_submitted.body', $this->count, ['count' => $this->count]))
            ->icon(asset('images/notifications/icon.png'))
            // A newer one replaces it instead of piling up.
            ->tag('review-submitted')
            ->data(['url' => route('library')]);
    }
}
