<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Series;

final readonly class RenameSeriesCommand
{
    /** @param string $newName raw name as typed; the handler normalises it */
    public function __construct(
        public Series $series,
        public string $newName,
    ) {
    }
}
