<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\CardReporter;
use App\Module\Project\Entity\Project;

/**
 * Dispatched inside DeleteBoardColumnHandler's transaction, after the flush that
 * removed the column. The event carries the column's values, because that flush
 * clears the removed entity's id. A listener must never throw, for the reason
 * CardMoved gives.
 */
final readonly class BoardColumnDeleted
{
    /**
     * @param ?string      $targetSlug   null when the column held no cards
     * @param list<string> $movedCardIds
     */
    public function __construct(
        public Project $project,
        public string $columnId,
        public string $slug,
        public ?string $targetSlug,
        public array $movedCardIds,
        public CardReporter $actor,
    ) {
    }
}
