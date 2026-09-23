<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/**
 * What a state report did. A null run means the owner has no project by that
 * handle. A false $newState means the run already held the state, so nothing
 * changed.
 */
final readonly class ReportWorkerRunStateResult
{
    public function __construct(
        public ?WorkerRun $run,
        public bool $newState,
    ) {
    }
}
