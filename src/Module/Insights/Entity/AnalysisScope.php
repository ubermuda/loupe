<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Bridge\Metric\MetricRange;

/** Which runs an analysis reads. */
final readonly class AnalysisScope
{
    public function __construct(
        public MetricRange $range,
        /** The experiment an experiment analysis compares. Null for any other topic. */
        public ?string $experiment = null,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $range = \is_string($data['range'] ?? null) ? MetricRange::tryFrom($data['range']) : null;
        $experiment = $data['experiment'] ?? null;

        return new self(
            $range ?? throw new \UnexpectedValueException('An analysis scope names a range.'),
            \is_string($experiment) ? $experiment : null,
        );
    }

    /** @return array{range: string, experiment?: string} */
    public function toArray(): array
    {
        $data = ['range' => $this->range->value];
        if (null !== $this->experiment) {
            $data['experiment'] = $this->experiment;
        }

        return $data;
    }
}
