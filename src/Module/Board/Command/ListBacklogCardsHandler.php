<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Utils\PageList;

final readonly class ListBacklogCardsHandler
{
    public const int PER_PAGE = 25;

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    public function __invoke(ListBacklogCardsCommand $command): ListBacklogCardsView
    {
        $backlog = $command->backlog;
        $listQuery = $command->listQuery;

        $total = $this->cards->countInColumn($backlog);
        $filteredTotal = $listQuery->isNarrowed() ? $this->cards->countBacklogMatching($backlog, $listQuery) : $total;
        $totalPages = max(1, (int) ceil($filteredTotal / self::PER_PAGE));
        $clampedPage = PageList::clampedPage($listQuery->page, $filteredTotal, self::PER_PAGE);
        $page = $clampedPage ?? $listQuery->page;

        // No read past the end: a huge page number would overflow the offset.
        $items = null === $clampedPage && $filteredTotal > 0
            ? $this->cards->findBacklogPage($backlog, $listQuery, ($page - 1) * self::PER_PAGE, self::PER_PAGE)
            : [];
        $boardColumns = $this->boardColumns->findBoardColumns($backlog->project);

        return new ListBacklogCardsView(
            items: $items,
            pendingComments: $this->cardSiteReviewComments->pendingCountsForCards($items),
            total: $total,
            filteredTotal: $filteredTotal,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            clampedPage: $clampedPage,
            epics: $this->cards->findEpicsOfColumn($backlog),
            boardColumns: $boardColumns,
            nextColumn: array_find($boardColumns, static fn (BoardColumn $column): bool => !$column->terminal),
        );
    }
}
