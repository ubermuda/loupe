<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DecisionSelection;

final readonly class SelectDecisionOptionResult
{
    public function __construct(
        /** The stored answer, or null when the reviewer cleared the option instead. */
        public ?DecisionSelection $selection,
        /** The version the block was answered against. */
        public int $versionNumber,
    ) {
    }
}
