<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class ShowDecisionSummaryView
{
    /**
     * @param list<array{label: string, elementId: string, selected: list<string>}> $rows
     */
    public function __construct(
        public array $rows,
        public int $answeredCount,
    ) {
    }
}
