<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Metric\Metric;

final readonly class ListMetricsView
{
    /**
     * @param list<Metric> $metrics     the metrics whose key says all
     * @param list<string> $bucketNames the buckets with time on the runs of the project
     */
    public function __construct(
        public array $metrics,
        public array $bucketNames,
    ) {
    }
}
