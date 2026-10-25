<?php

namespace App\Enums;

enum AssetStatus: string
{
    /** Uploaded, metadata not finished yet. */
    case Draft = 'draft';

    /** Metadata complete: goes out with the next publishing batch. */
    case Ready = 'ready';

    /** Sent to every marketplace of its batch (App\Jobs\PublishListings); set by Kadrobit, never by the user. */
    case Submitted = 'submitted';

    /** Hidden from the working library. */
    case Archived = 'archived';
}
