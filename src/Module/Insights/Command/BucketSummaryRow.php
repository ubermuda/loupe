<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

final readonly class BucketSummaryRow
{
    public function __construct(
        public string $name,
        public int|float $total,
        public int|float|null $median,
        /** The share of the time of every listed bucket, null when no bucket has time. */
        public ?float $share,
        /** @var array<string, string>|null the Metrics query of the bucket, null when the bucket has no time */
        public ?array $metricsParams,
    ) {
    }
}
