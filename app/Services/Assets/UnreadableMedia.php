<?php

namespace App\Services\Assets;

use RuntimeException;

/**
 * The file cannot be read as the media it claims to be: a broken video, an EPS Ghostscript rejects,
 * an SVG with external references. Retrying would not help, so the upload fails at once.
 */
class UnreadableMedia extends RuntimeException {}
