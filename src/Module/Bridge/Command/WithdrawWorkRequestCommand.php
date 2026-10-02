<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

final readonly class WithdrawWorkRequestCommand
{
    public function __construct(
        public Uuid $workRequestId,
        /** Cancelled or expired. */
        public WorkRequestState $state,
    ) {
    }
}
