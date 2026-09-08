<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

/** The cards a picker offers. */
final readonly class SearchCardsView
{
    /** @param list<Card> $cards */
    public function __construct(
        public array $cards,
    ) {
    }
}
