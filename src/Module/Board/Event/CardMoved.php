<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Service\CardMove;

/**
 * Dispatched inside UpdateCardHandler's transaction, after its flush, so a
 * listener's rows commit with the move and need no flush of their own. A
 * listener that throws refuses the move, which keeps an epic and its children
 * in agreement. CardMove holds where the card came from.
 */
final readonly class CardMoved
{
    public function __construct(
        public Card $card,
        public CardMove $move,
        public CardReporter $actor,
    ) {
    }
}
