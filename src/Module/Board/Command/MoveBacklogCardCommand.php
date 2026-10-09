<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\Actor;

final readonly class MoveBacklogCardCommand
{
    public function __construct(
        public Card $card,
        public Actor $actor,
        /** A column the board draws. The card lands at its end. */
        public BoardColumn $column,
    ) {
    }
}
