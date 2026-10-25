<?php

namespace App\Services\Metadata;

use RuntimeException;

/**
 * The provider failed or answered with something unusable. The message is safe to log: it never holds the key.
 */
class GenerationException extends RuntimeException {}
