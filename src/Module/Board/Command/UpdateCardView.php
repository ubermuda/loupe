<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;

/** $openedRun is the run the update opened, or null when the command opened none. */
final readonly class UpdateCardView
{
    public function __construct(
        public Card $card,
        public ?WorkerRun $openedRun,
    ) {
    }
}
