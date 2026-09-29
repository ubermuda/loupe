<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;

/** The card ids of one Backlog page, for a move to compare with the page after it. */
final readonly class ListBacklogPageIdsHandler
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /** @return list<string> */
    public function __invoke(ListBacklogPageIdsCommand $command): array
    {
        $perPage = ListBacklogCardsHandler::PER_PAGE;
        // A huge page number would overflow the offset, and shows no row.
        if ($command->listQuery->page > intdiv(\PHP_INT_MAX, $perPage)) {
            return [];
        }

        return $this->cards->findBacklogPageIds($command->backlog, $command->listQuery, ($command->listQuery->page - 1) * $perPage, $perPage);
    }
}
