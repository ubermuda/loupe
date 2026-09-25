<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;

final readonly class OpenCardRunView
{
    public function __construct(
        public Card $card,
        public WorkerRun $run,
    ) {
    }
}
