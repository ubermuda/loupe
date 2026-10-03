<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\ValueObject\WorkerRunState;

/** The latest outcome of a card's runs, when that outcome needs a person: gave-up or blocked. */
final readonly class CardRunWarning
{
    public function __construct(
        public string $runId,
        public WorkerRunState $state,
        public string $summary,
    ) {
    }
}
