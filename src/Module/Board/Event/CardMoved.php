<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardMove;

/**
 * Dispatched inside UpdateCardHandler's transaction, after the flush that wrote
 * the move. A listener that persists rows needs no flush of its own, because
 * the transaction flushes again when it closes.
 *
 * The event class is the module's public API, the same contract ProjectDeleting
 * carries. Board does not know who listens, and a listener must never throw:
 * anything it raises aborts the move it was told about.
 *
 * CardMove holds where the card came from, and the card holds where it arrived.
 */
final readonly class CardMoved
{
    public function __construct(
        public Card $card,
        public CardMove $move,
    ) {
    }
}
