<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Where a unit of work stands. The values match the states the ledger stores. */
enum WorkState: string
{
    case Open = 'open';

    case Claimed = 'claimed';

    case Done = 'done';

    case Refused = 'refused';

    case Expired = 'expired';

    case Cancelled = 'cancelled';
}
