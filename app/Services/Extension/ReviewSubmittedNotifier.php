<?php

namespace App\Services\Extension;

use App\Enums\ListingStatus;
use App\Enums\UserSettingKey;
use App\Models\AssetListing;
use App\Models\User;
use App\Notifications\Push\ReviewSubmittedNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Works sent to review" browser notification. The works of one send reach review over several passes of the
 * extension (marketplaces show the files minutes apart), so they add up, and one notification goes out once nothing
 * is on its way any more: the moment the publish button in the library stops spinning (AssetListing::awaitsReview(),
 * a work never shown on the marketplace stops waiting after extension.await_review_hours). Every pass of the extension
 * reports, even with nothing found, so a send that ends otherwise (failed, cancelled, never shown) is settled too.
 */
class ReviewSubmittedNotifier
{
    /**
     * After a report of the extension.
     *
     * @param  int  $reached  Works the report moved into review
     */
    public function record(User $user, int $reached): void
    {
        $key = "review-submitted:{$user->getKey()}";

        if ($reached > 0) {
            Cache::add($key, 0, now()->addHours(config('extension.await_review_hours') + 1));
            $total = Cache::increment($key, $reached);

            Log::info('Works reached review', ['user' => $user->getKey(), 'reached' => $reached, 'total' => $total]);
        }

        if (! Cache::get($key) || $this->inProgress($user)) {
            return;
        }

        // Two reports at once may both get here: only the one that takes the count notifies.
        $count = (int) Cache::pull($key);

        if ($count > 0 && $user->setting(UserSettingKey::NotifyReviewSubmitted) && $user->pushSubscriptions()->exists()) {
            $user->notify(new ReviewSubmittedNotification($count));

            Log::info('Review submitted notification', ['user' => $user->getKey(), 'count' => $count]);
        }
    }

    /**
     * Some work of the user is in the queue, being uploaded, or waiting for the extension to submit it.
     */
    protected function inProgress(User $user): bool
    {
        $listings = AssetListing::query()->whereHas('account', fn ($query) => $query->where('user_id', $user->getKey()));

        if ((clone $listings)->whereIn('status', ListingStatus::PENDING)->exists()) {
            return true;
        }

        // Nothing waits for an extension that is not connected.
        if (! ExtensionTokens::connected($user->getKey())) {
            return false;
        }

        // The query narrows down; awaitsReview() decides on the account's own settings. One is enough.
        return $listings
            ->with('account.marketplace')
            ->where('status', ListingStatus::Sent)
            ->whereNull('moderation_status')
            ->where('sent_at', '>', now()->subHours(config('extension.await_review_hours')))
            ->lazyById(100)
            ->contains(fn (AssetListing $listing) => $listing->awaitsReview());
    }
}
