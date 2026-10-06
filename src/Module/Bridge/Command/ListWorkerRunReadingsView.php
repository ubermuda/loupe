<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Entity\WorkerRunUsage;

final readonly class ListWorkerRunReadingsView
{
    /**
     * @param array<string, list<WorkerRunUsage>> $usage run id => usage rows, by model
     * @param array<string, WorkerRunFact>        $facts run id => fact row
     */
    public function __construct(
        public array $usage,
        public array $facts,
    ) {
    }
}
