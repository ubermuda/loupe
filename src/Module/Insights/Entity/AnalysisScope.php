<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Bridge\Metric\MetricRange;

/** Which runs an analysis reads. */
final readonly class AnalysisScope
{
    public function __construct(
        public MetricRange $range,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $range = \is_string($data['range'] ?? null) ? MetricRange::tryFrom($data['range']) : null;

        return new self($range ?? throw new \UnexpectedValueException('An analysis scope names a range.'));
    }

    /** @return array{range: string} */
    public function toArray(): array
    {
        return ['range' => $this->range->value];
    }
}
