<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Project\Entity\Project;

final readonly class ShowBucketSummaryCommand
{
    public function __construct(
        public Project $project,
        public MetricRange $range,
    ) {
    }
}
