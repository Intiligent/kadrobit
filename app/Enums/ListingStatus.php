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

    /** In the queue or on its way: the work cannot be deleted and the listing is not queued again. */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Sending;
    }

    /** The next batch may queue it again. */
    public function isRetryable(): bool
    {
        return $this === self::Failed || $this === self::Cancelled;
    }
}
