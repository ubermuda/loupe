<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardPause;

final readonly class ReleaseCardPauseCommand
{
    public function __construct(
        public CardPause $pause,
        public string $reason,
    ) {
    }
}
