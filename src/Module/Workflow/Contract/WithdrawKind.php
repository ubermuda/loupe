<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** How the workflow ends a live unit of work. */
enum WithdrawKind
{
    case Cancelled;

    case Expired;
}
