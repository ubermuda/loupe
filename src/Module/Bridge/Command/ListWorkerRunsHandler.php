<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Utils\PageList;
use Psr\Clock\ClockInterface;

final readonly class ListWorkerRunsHandler
{
    public const int PER_PAGE = 20;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunStateChangeRepository $workerRunStateChanges,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ListWorkerRunsCommand $command): ListWorkerRunsView
    {
        $listQuery = $command->listQuery;
        // Clamped rather than refused: an out-of-range page size should be
        // brought into range, not fail the call.
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX overflows the offset multiplication to a
        // float, which setFirstResult() then refuses.
        $page = min(max(1, $listQuery->page), intdiv(\PHP_INT_MAX, $perPage));

        $paginator = $this->workerRuns->findPaginatedByProject(
            $command->project,
            $page,
            $perPage,
            $listQuery->search,
            $listQuery->state,
            $listQuery->bridgeId,
        );
        $total = \count($paginator);
        $totalPages = max(1, (int) ceil($total / $perPage));

        /** @var list<WorkerRun> $runs */
        $runs = array_values(iterator_to_array($paginator, false));

        $now = $this->clock->now();
        $histories = $this->workerRunStateChanges->findForRuns($runs);

        return new ListWorkerRunsView(
            items: array_map(
                static fn (WorkerRun $run): WorkerRunListItem => new WorkerRunListItem($run, $now, $histories[(string) $run->id] ?? []),
                $runs,
            ),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            bridgeIds: $this->workerRuns->bridgeIdsOf($command->project),
            clampedPage: PageList::clampedPage($page, $total, $perPage),
        );
    }
}
