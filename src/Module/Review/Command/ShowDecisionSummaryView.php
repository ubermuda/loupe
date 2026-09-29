<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class ShowDecisionSummaryView
{
    /**
     * @param list<array{label: string, elementId: string, answered: bool, selected: list<string>}> $rows
     * @param array<string, array{indexes: list<int>, note: string|null}>                           $answers keyed by decision id
     */
    public function __construct(
        public array $rows,
        public int $answeredCount,
        public array $answers,
    ) {
    }
}
