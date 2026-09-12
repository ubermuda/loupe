<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;

/**
 * What one rename left behind: the series under its new name, and how many
 * documents it holds.
 */
final readonly class RenameSeriesOutcome
{
    public function __construct(
        public Series $series,
        public int $documentCount,
    ) {
    }
}
