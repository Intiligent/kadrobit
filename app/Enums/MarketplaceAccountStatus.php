<?php

namespace App\Enums;

enum MarketplaceAccountStatus: string
{
    case Active = 'active';

    /** The last connection check failed with an auth error. */
    case InvalidCredentials = 'invalid_credentials';

    /** Turned off by the user: excluded from publishing. */
    case Disabled = 'disabled';
}
