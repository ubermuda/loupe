<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardStatus;

/** One column of the board, with the cards the page shows in it. */
final readonly class BoardColumnView
{
    /** @param list<BoardGroupView> $groups */
    public function __construct(
        public CardStatus $status,
        public array $groups,
        public int $count,
        /** Whether a card dropped here takes a rank. Done sorts by completion, so it does not. */
        public bool $rankable,
    ) {
    }
}
