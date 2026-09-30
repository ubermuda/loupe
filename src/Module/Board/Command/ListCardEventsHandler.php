<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardEventRepository;

/** Reads one page of a card's history, newest first, with the same paging rules as the board. */
final readonly class ListCardEventsHandler
{
    public function __construct(
        private CardEventRepository $cardEvents,
    ) {
    }

    public function __invoke(ListCardEventsCommand $command): ListCardEventsView
    {
        $page = max(1, $command->page);
        $perPage = min(ListCardsHandler::MAX_PER_PAGE, max(1, $command->perPage));

        $total = $this->cardEvents->countForCard($command->card);
        // Capped before the multiplication: a page near PHP_INT_MAX would overflow to a float.
        $offset = $page - 1 > intdiv($total, $perPage) ? $total : ($page - 1) * $perPage;

        return new ListCardEventsView(
            events: $offset < $total ? $this->cardEvents->findPageForCard($command->card, $offset, $perPage) : [],
            page: $page,
            perPage: $perPage,
            total: $total,
            hasMore: $offset + $perPage < $total,
        );
    }
}
