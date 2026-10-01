<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Project\Entity\Project;

final readonly class ExperimentReportView
{
    /**
     * @param list<ExperimentVariant>         $variants by name
     * @param array<string, ExperimentMetric> $metrics  key => metric, in display order
     * @param list<ExperimentCard>            $cards    one page of the Cards tab, the latest run first
     * @param list<int|null>                  $pageList
     */
    public function __construct(
        public Project $project,
        public string $experiment,
        public ExperimentHeadline $headline,
        public array $variants,
        public array $metrics,
        public int $includedCards,
        public int $leftOutCards,
        public array $cards,
        public int $filteredTotal,
        public int $totalPages,
        public array $pageList,
        public ?int $clampedPage,
        public int $page,
        public ?string $variantFilter,
        public bool $leftOutOnly,
    ) {
    }
}
