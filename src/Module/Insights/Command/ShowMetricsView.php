<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Command\MetricQueryView;
use App\Module\Project\Entity\Project;

final readonly class ShowMetricsView
{
    /**
     * @param array<string, string> $groupLabels the label of each bridge group, by bridge id
     * @param array<string, int>    $keptRunIds  the shown runs that the retention sweep kept, as keys
     */
    public function __construct(
        public Project $project,
        public MetricQueryView $metrics,
        public array $groupLabels,
        public array $keptRunIds,
    ) {
    }
}
