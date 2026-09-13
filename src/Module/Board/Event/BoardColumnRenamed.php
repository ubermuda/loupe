<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;

/**
 * Dispatched inside RenameBoardColumnHandler's transaction, after the flush,
 * for every rename, including one whose slug stays the same. A listener must
 * never throw, for the reason CardMoved gives.
 */
final readonly class BoardColumnRenamed
{
    public function __construct(
        public BoardColumn $column,
        public string $fromSlug,
        public string $toSlug,
        public CardReporter $actor,
    ) {
    }
}
