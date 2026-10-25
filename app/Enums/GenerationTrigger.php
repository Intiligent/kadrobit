<?php

namespace App\Enums;

/**
 * What started a metadata generation.
 */
enum GenerationTrigger: string
{
    /** After the upload was processed, with the "Fill in metadata on upload" setting on: fills empty fields only. */
    case Upload = 'upload';

    /** The user asked for it. */
    case Manual = 'manual';
}
