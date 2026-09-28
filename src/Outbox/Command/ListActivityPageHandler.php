<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Outbox\ActivityEntryBuilder;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\Repository\OutboxEventRepository;
use App\Utils\PageList;

final readonly class ListActivityPageHandler
{
    public const int PER_PAGE = 20;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private OutboxEventRepository $outboxEvents,
        private ActivityEntryBuilder $entries,
    ) {
    }

    public function __invoke(ListActivityPageCommand $command): ListActivityPageView
    {
        $listQuery = $command->listQuery;
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX overflows the offset multiplication to a
        // float, which setFirstResult() then refuses.
        $page = min(max(1, $listQuery->page), intdiv(\PHP_INT_MAX, $perPage));

        $paginator = $this->outboxEvents->findPaginatedForProject(
            $command->project,
            $page,
            $perPage,
            $listQuery->search,
            $listQuery->family,
        );
        $total = \count($paginator);
        $totalPages = max(1, (int) ceil($total / $perPage));

        /** @var list<OutboxEvent> $events */
        $events = array_values(iterator_to_array($paginator, false));

        return new ListActivityPageView(
            entries: $this->entries->entriesFor($command->project, $events),
            filteredTotal: $total,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            clampedPage: PageList::clampedPage($page, $total, $perPage),
        );
    }
}
