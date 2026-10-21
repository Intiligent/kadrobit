<?php

namespace App\Enums;

enum AssetStatus: string
{
    /** Uploaded, metadata not finished yet. */
    case Draft = 'draft';

    /** Metadata complete, can be published. */
    case Ready = 'ready';

    /** Hidden from the working library. */
    case Archived = 'archived';
}
