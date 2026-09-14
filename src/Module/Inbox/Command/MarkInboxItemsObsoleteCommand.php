<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Board\Entity\Card;

/** A card has moved. Its column says whether the card is now finished. */
final readonly class MarkInboxItemsObsoleteCommand
{
    public function __construct(
        public Card $card,
    ) {
    }
}
