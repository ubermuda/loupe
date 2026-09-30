<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;

final readonly class WorkerRunSeriesView
{
    /**
     * @param list<WorkerRun>                           $runs         the whole series, oldest report first
     * @param array<string, list<WorkerRunStateChange>> $stateChanges keyed by the run id, oldest first
     * @param list<BridgeCommand>                       $commands     every command of the series, oldest request first
     */
    public function __construct(
        public WorkerRun $run,
        public array $runs,
        public array $stateChanges,
        public array $commands,
    ) {
    }
}
