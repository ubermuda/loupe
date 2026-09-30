<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

final readonly class ShowWorkerRunSeriesCommand
{
    public function __construct(
        public WorkerRun $run,
    ) {
    }
}
