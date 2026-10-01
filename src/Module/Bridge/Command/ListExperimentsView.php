<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentSummary;

final readonly class ListExperimentsView
{
    /**
     * @param list<ExperimentSummary> $experiments the latest run first, and an experiment with no run last
     */
    public function __construct(
        public array $experiments,
    ) {
    }
}
