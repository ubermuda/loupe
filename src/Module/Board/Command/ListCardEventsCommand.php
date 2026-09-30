<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

/** One page of a card's history. The handler clamps the paging. */
final readonly class ListCardEventsCommand
{
    public function __construct(
        public Card $card,
        public int $page = 1,
        public int $perPage = ListCardsHandler::DEFAULT_PER_PAGE,
    ) {
    }
}
