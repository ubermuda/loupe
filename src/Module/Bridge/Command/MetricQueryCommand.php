<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Project\Entity\Project;

final readonly class MetricQueryCommand
{
    public function __construct(
        public Project $project,
        public MetricUnit $unit,
        public Metric $metric,
        public MetricStatistic $statistic,
        public MetricGroup $group,
        public MetricRange $range,
        public MetricBucket $bucket,
    ) {
    }
}
