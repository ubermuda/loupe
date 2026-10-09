<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

enum GitHubUserTokenStatus: string
{
    case Fresh = 'fresh';

    /** GitHub refused the refresh token for good. The person must connect again. */
    case Expired = 'expired';

    case NotConnected = 'not-connected';

    /** GitHub did not answer. The row is unchanged and a retry may work. */
    case Unavailable = 'unavailable';
}
