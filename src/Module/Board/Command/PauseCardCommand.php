<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPauseKind;

final readonly class PauseCardCommand
{
    public function __construct(
        public Card $card,
        public string $reason,
        public string $ruleId,
        public CardPauseKind $kind,
    ) {
    }
}
