<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;
use App\Utils\PageList;

final readonly class ListBacklogCardsHandler
{
    public const int PER_PAGE = 25;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(ListBacklogCardsCommand $command): ListBacklogCardsView
    {
        $total = $this->cards->countInColumn($command->backlog);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $clampedPage = PageList::clampedPage($command->page, $total, self::PER_PAGE);
        $page = $clampedPage ?? $command->page;

        return new ListBacklogCardsView(
            items: $this->cards->findRankedPage($command->backlog, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            total: $total,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            clampedPage: $clampedPage,
        );
    }
}
