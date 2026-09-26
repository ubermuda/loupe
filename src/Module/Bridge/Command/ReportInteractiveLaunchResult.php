<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/** A null run means the owner has no project by that handle. */
final readonly class ReportInteractiveLaunchResult
{
    public function __construct(
        public ?WorkerRun $run,
        public bool $created,
    ) {
    }
}
