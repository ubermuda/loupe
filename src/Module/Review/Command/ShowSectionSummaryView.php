<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class ShowSectionSummaryView
{
    /**
     * @param list<array{headingId: string, level: int, label: string, approved: bool}> $rows
     */
    public function __construct(
        public array $rows,
        public int $approvedCount,
    ) {
    }
}
