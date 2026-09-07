<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;

final readonly class MoveCardCommand
{
    /**
     * @param ?int $position the rank the card takes inside the target group,
     *                       counting from 0; null appends it to the end
     */
    public function __construct(
        public Card $card,
        public CardStatus $status,
        public CardPriority $priority,
        public ?int $position = null,
    ) {
    }
}
