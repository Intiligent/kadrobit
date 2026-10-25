<?php

namespace App\Enums;

/**
 * Delivery state of a work to one marketplace account (asset_listings).
 */
enum ListingStatus: string
{
    /** Waiting for the account's publishing session. */
    case Queued = 'queued';

    /** Being uploaded right now. */
    case Sending = 'sending';

    /** On the marketplace's server; the final Submit is done in its portal. */
    case Sent = 'sent';

    /** Gave up; the reason is in asset_listings.error. Can be retried. */
    case Failed = 'failed';

    /** Taken out of the queue by the user or because the work left the batch. Can be retried. */
    case Cancelled = 'cancelled';

    /** In the queue or on its way: not queued again. */
    public const PENDING = [self::Queued, self::Sending];

    /** The next batch or a retry may queue it again. */
    public const RETRYABLE = [self::Failed, self::Cancelled];

    public function isPending(): bool
    {
        return in_array($this, self::PENDING, true);
    }

    public function isRetryable(): bool
    {
        return in_array($this, self::RETRYABLE, true);
    }
}
