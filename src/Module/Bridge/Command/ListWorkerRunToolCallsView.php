<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRunToolCall;

final readonly class ListWorkerRunToolCallsView
{
    /** @param list<WorkerRunToolCall> $calls */
    public function __construct(
        public array $calls,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }
}
