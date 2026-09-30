<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardEvent;

/** One page of a card's history, newest first, with the paging the caller asked for. */
final readonly class ListCardEventsView
{
    /** @param list<CardEvent> $events */
    public function __construct(
        public array $events,
        public int $page,
        public int $perPage,
        public int $total,
        public bool $hasMore,
    ) {
    }
}
