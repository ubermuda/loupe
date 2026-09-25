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
        /** The column that started the run's series. Null on a run from an older bridge. */
        public ?string $cardColumn,
    ) {
    }

    /** A card moved out of the column that started the run no longer carries the warning. */
    public function appliesTo(string $columnSlug): bool
    {
        return null === $this->cardColumn || $this->cardColumn === $columnSlug;
    }
}
