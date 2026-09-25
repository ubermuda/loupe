<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

/** One row of a board with lanes: an epic and its children, or the "Other cards" row when the epic is null. */
final readonly class BoardLaneView
{
    /** @param array<string, list<Card>> $cells column id => the row's cards in that column, in board order; an empty cell has no key */
    public function __construct(
        public ?Card $epic,
        public array $cells,
    ) {
    }
}
