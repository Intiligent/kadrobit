<?php

namespace App\Services\Metadata;

use App\Exceptions\ExceptionWithMessage;

/**
 * The user has used up this month's generations (config metadata.monthly_limit).
 */
class GenerationLimitReached extends ExceptionWithMessage
{
    public function __construct()
    {
        parent::__construct(__('library.generation.limit_reached', ['limit' => config('metadata.monthly_limit')]), 429);
    }
}
