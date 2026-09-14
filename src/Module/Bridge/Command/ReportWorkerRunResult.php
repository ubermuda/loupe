<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/**
 * What a report did. A null run means the owner has no project by that handle,
 * and a false $created means the server already held this report.
 */
final readonly class ReportWorkerRunResult
{
    public function __construct(
        public ?WorkerRun $run,
        public bool $created,
    ) {
    }
}
