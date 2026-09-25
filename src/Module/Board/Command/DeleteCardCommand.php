<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;

final readonly class DeleteCardCommand
{
    public function __construct(
        public Card $card,
        /** Who deletes the card: a person on the board, or a reviewer who deletes the note that made it. */
        public CardReporter $actor,
    ) {
    }
}
