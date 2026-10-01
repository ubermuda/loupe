<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;

final readonly class CancelBridgeCommandCommand
{
    public function __construct(
        public WorkerRun $run,
        public User $requestedBy,
    ) {
    }
}
