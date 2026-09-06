<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

final readonly class ListDoneCardsView
{
    /**
     * @param list<Card>     $items
     * @param list<int|null> $pageList
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $totalPages,
        public array $pageList,
        /** The page to redirect to when the request asked for one past the end, else null. */
        public ?int $clampedPage,
    ) {
    }
}
