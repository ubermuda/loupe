<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRunToolCall;
use App\Module\Bridge\Repository\WorkerRunToolCallRepository;

/** One page of the tool calls of a run, by seq. An out-of-range page or page size is clamped, not refused. */
final readonly class ListWorkerRunToolCallsHandler
{
    public const int PER_PAGE = 20;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private WorkerRunToolCallRepository $workerRunToolCalls,
    ) {
    }

    public function __invoke(ListWorkerRunToolCallsCommand $command): ListWorkerRunToolCallsView
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX overflows the offset to a float, which setFirstResult() refuses.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, $perPage));

        $paginator = $this->workerRunToolCalls->findPageOfRun($command->run, $page, $perPage);
        /** @var list<WorkerRunToolCall> $calls */
        $calls = array_values(iterator_to_array($paginator, false));

        return new ListWorkerRunToolCallsView($calls, $page, $perPage, \count($paginator));
    }
}
