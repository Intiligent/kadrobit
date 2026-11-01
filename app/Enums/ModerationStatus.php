<?php

namespace App\Enums;

/**
 * Review state of a sent work on the marketplace (asset_listings.moderation_status), as the browser
 * extension reads it on the marketplace's site. Null while it is not known.
 */
enum ModerationStatus: string
{
    /** Waiting for the marketplace's review. */
    case Pending = 'pending';

    /** Accepted: on sale. */
    case Accepted = 'accepted';

    /** Rejected by the reviewers. */
    case Rejected = 'rejected';
}
