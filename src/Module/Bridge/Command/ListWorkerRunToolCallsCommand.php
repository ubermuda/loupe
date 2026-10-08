<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

final readonly class ListWorkerRunToolCallsCommand
{
    public function __construct(
        public WorkerRun $run,
        public int $page = 1,
        public int $perPage = ListWorkerRunToolCallsHandler::PER_PAGE,
    ) {
    }
}
