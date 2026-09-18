<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class SaveDecisionCommand
{
    /**
     * @param list<int> $optionIndexes
     * @param list<int> $expectedOptionIndexes
     */
    public function __construct(
        public Document $document,
        public string $decisionId,
        public int $displayedVersionNumber,
        public array $optionIndexes,
        public array $expectedOptionIndexes,
    ) {
    }
}
