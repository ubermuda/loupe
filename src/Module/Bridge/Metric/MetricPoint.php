<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** A statistic over some rows, and the number of rows with a known value behind it. */
final readonly class MetricPoint
{
    public function __construct(
        /** The start of the bucket. Null for the total of a series. */
        public ?\DateTimeImmutable $start,
        public int|float|null $value,
        public int $rows,
    ) {
    }
}
