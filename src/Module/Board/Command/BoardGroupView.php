<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;

/**
 * One run of cards under a heading inside a column.
 *
 * $priority is null in Done, which grades nothing and keeps its cards in one
 * run ordered by completion. Every other column has one group per priority, and
 * an empty one still renders, because it is where a card is dropped to take
 * that grade.
 */
final readonly class BoardGroupView
{
    /** @param list<Card> $cards */
    public function __construct(
        public ?CardPriority $priority,
        public array $cards,
    ) {
    }
}
