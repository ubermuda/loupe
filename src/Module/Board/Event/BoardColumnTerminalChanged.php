<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;

/**
 * Dispatched by TerminalColumnCards inside the transaction that flipped the
 * flag, after the column's cards followed it. A listener must never throw, for
 * the reason CardMoved gives.
 */
final readonly class BoardColumnTerminalChanged
{
    /** @param list<string> $cardIds the cards in the column */
    public function __construct(
        public Project $project,
        public string $columnId,
        public bool $terminal,
        public array $cardIds,
        public Actor $actor,
    ) {
    }
}
