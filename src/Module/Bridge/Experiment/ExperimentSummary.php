<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

final readonly class ExperimentSummary
{
    public function __construct(
        public string $name,
        public int $cards,
        /** Null when only pins name the experiment. */
        public ?\DateTimeImmutable $lastRunAt,
    ) {
    }
}
