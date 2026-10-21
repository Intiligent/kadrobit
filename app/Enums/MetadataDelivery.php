<?php

namespace App\Enums;

/**
 * How a marketplace receives metadata (title, description, keywords).
 */
enum MetadataDelivery: string
{
    /** Embedded into the file as IPTC/XMP before upload. */
    case Iptc = 'iptc';

    /** Uploaded separately as a CSV file. */
    case Csv = 'csv';

    /** Sent via the marketplace API. */
    case Api = 'api';

    /** Entered by hand in the marketplace web portal. */
    case Manual = 'manual';
}
