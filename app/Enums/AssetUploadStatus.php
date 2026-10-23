<?php

namespace App\Enums;

/**
 * Where an uploaded file is on its way to becoming a work (ProcessAssetUpload).
 */
enum AssetUploadStatus: string
{
    /** Received and waiting in the queue, or being processed. */
    case Processing = 'processing';

    /** The work exists. */
    case Done = 'done';

    /** No work was created; `error` says why. */
    case Failed = 'failed';
}
