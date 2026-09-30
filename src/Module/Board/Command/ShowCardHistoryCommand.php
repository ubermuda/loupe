<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;

final readonly class ShowCardHistoryCommand
{
    public function __construct(
        public Card $card,
        public int $offset = 0,
    ) {
    }
}
