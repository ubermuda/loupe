<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;
use App\Utils\PageList;

final readonly class ListDoneCardsHandler
{
    public const int PER_PAGE = 25;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(ListDoneCardsCommand $command): ListDoneCardsView
    {
        $total = $this->cards->countDone($command->project);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $clampedPage = PageList::clampedPage($command->page, $total, self::PER_PAGE);
        $page = $clampedPage ?? $command->page;

        return new ListDoneCardsView(
            items: $this->cards->findDonePage($command->project, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            total: $total,
            totalPages: $totalPages,
            pageList: PageList::build($page, $totalPages),
            clampedPage: $clampedPage,
        );
    }
}
