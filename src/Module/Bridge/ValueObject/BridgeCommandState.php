<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** A command waits as pending until the bridge settles it or its time runs out. */
enum BridgeCommandState: string
{
    case Pending = 'pending';

    case Done = 'done';

    case Refused = 'refused';

    case Expired = 'expired';

    case Cancelled = 'cancelled';
}
