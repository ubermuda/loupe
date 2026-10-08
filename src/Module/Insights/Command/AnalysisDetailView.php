<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\Proposal;

final readonly class AnalysisDetailView
{
    /** @param list<Proposal> $proposals in the order the agent reported them */
    public function __construct(
        public Analysis $analysis,
        /** Micro US dollars, null when no run has a known cost. */
        public ?int $costMicroUsd,
        public array $proposals,
    ) {
    }
}
