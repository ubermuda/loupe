<?php

declare(strict_types=1);

namespace App\Module\Readiness\Entity;

enum DiscoveryRunState: string
{
    case Requested = 'requested';
    case Reported = 'reported';
    case Failed = 'failed';
    case Done = 'done';
}
