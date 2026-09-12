<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;

/**
 * Every series of one project, with how far each one runs.
 *
 * @phpstan-type SeriesCount array{series: Series, documentCount: int, highestOrdinal: ?int}
 */
final readonly class ListSeriesView
{
    /** @param list<SeriesCount> $series */
    public function __construct(
        public array $series,
    ) {
    }
}
