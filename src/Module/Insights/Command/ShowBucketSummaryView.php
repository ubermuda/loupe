<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Project\Entity\Project;

/** The time in each bucket over the closed runs of a range. */
final readonly class ShowBucketSummaryView
{
    public function __construct(
        public Project $project,
        public MetricRange $range,
        /** @var list<BucketSummaryRow> the rule buckets in rule order, then the fallback, then the other buckets by name */
        public array $rows,
        public int $knownRuns,
        public int $runs,
    ) {
    }
}
