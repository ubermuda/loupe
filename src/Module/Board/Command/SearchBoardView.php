<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

/**
 * One page of search results, with the counts a caller needs to walk the rest.
 *
 * The page and per-page it carries are the clamped values the handler used, not
 * the ones asked for, so a caller reports what it read rather than what it
 * requested.
 */
final readonly class SearchBoardView
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
