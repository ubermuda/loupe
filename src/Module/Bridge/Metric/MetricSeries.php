<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

final readonly class MetricSeries
{
    /**
     * @param list<MetricPoint> $points oldest bucket first, only the buckets with rows
     * @param list<MetricRow>   $rows   newest first
     */
    public function __construct(
        public ?string $group,
        public array $points,
        public MetricPoint $total,
        public array $rows,
    ) {
    }
}
