<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardReporter;

final readonly class MoveCardCommand
{
    /**
     * @param ?int $position the rank the card takes inside the target group,
     *                       counting from 0; null appends it to the end
     */
    public function __construct(
        public Card $card,
        public CardReporter $actor,
        public BoardColumn $column,
        public CardPriority $priority,
        public ?int $position = null,
    ) {
    }
}
