<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

use Symfony\Component\Uid\Uuid;

/** One run or one card behind a metric. A null value is unknown and stays out of the statistics. */
final readonly class MetricRow
{
    public function __construct(
        /** The run id or the card id. */
        public Uuid $unitId,
        public ?int $cardNumber,
        public ?string $group,
        public \DateTimeImmutable $time,
        public int|float|null $value,
    ) {
    }
}
