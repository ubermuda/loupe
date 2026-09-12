<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

/** One page of a board read, with the paging the caller asked for. */
final readonly class ListCardsView
{
    /** @param list<Card> $cards */
    public function __construct(
        public array $cards,
        public int $page,
        public int $perPage,
        public int $total,
        public bool $hasMore,
    ) {
    }
}
