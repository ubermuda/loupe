<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardLinkKind;

/** One link a card write asks for: the other card, and how the written card reads it. */
final readonly class CardLinkInput
{
    public function __construct(
        public string $cardId,
        public CardLinkKind $kind = CardLinkKind::RelatesTo,
    ) {
    }
}
