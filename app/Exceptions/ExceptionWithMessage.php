<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A business error whose message is safe and meant to be shown to the user.
 */
class ExceptionWithMessage extends HttpException
{
    public function __construct(string $message, int $status = 400, ?Throwable $previous = null, array $headers = [])
    {
        parent::__construct($status, $message, $previous, $headers);
    }
}
