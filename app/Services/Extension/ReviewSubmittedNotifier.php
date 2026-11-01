<?php

namespace App\Services\Extension;

use App\Enums\ListingStatus;
use App\Enums\UserSettingKey;
use App\Models\AssetListing;
use App\Models\User;
use App\Notifications\Push\ReviewSubmittedNotification;
use Illuminate\Support\Facades\Cache;

/**
 * "Works sent to review" browser notification. The works of one send reach review over several passes of the
 * extension (marketplaces show the files minutes apart), so they add up, and one notification goes out once nothing
 * is on its way any more: the moment the publish button in the library stops spinning (AssetListing::awaitsReview(),
 * a work never shown on the marketplace stops waiting after extension.await_review_hours).
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
            Cache::increment($key, $reached);
        }

        if (! Cache::get($key) || $this->inProgress($user)) {
            return;
        }

        $count = (int) Cache::pull($key);

        if ($user->setting(UserSettingKey::NotifyReviewSubmitted) && $user->pushSubscriptions()->exists()) {
            $user->notify(new ReviewSubmittedNotification($count));
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

        return $listings
            ->with('account.marketplace')
            ->where('status', ListingStatus::Sent)
            ->whereNull('moderation_status')
            ->where('sent_at', '>', now()->subHours(config('extension.await_review_hours')))
            ->get()
            ->contains(fn (AssetListing $listing) => $listing->awaitsReview());
    }
}
