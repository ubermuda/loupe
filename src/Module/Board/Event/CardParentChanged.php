<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;

/**
 * Dispatched inside the transaction of the card write, after its flush, when
 * the card joins an epic, leaves one or changes epic. A null side means no epic.
 */
final readonly class CardParentChanged
{
    public function __construct(
        public Card $card,
        public ?Card $oldParent,
        public ?Card $newParent,
        public CardReporter $actor,
    ) {
    }
}
