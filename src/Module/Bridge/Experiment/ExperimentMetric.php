<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricValueType;

/** One row of the comparison: a range per variant, and whether the first two variants give a clear answer. */
final readonly class ExperimentMetric
{
    public const string MERGE_RATE = Metric::MergeRate->value;

    public const string STOP_RATE = Metric::StopRate->value;

    public const string FIX_ROUNDS = Metric::FixRounds->value;

    public const string COST = Metric::Cost->value;

    public const string OUTPUT_TOKENS = Metric::OutputTokens->value;

    public const string HOURS_TO_MERGE = Metric::HoursToMerge->value;

    /**
     * @param array<string, ?Interval> $byVariant variant name => range, null when the variant has no data
     * @param list<ExperimentMetric>   $parts     the rows under this one, such as the fix rounds of each reason
     */
    public function __construct(
        public string $key,
        public array $byVariant,
        public bool $clear,
        public array $parts = [],
    ) {
    }

    public function for(string $variant): ?Interval
    {
        return $this->byVariant[$variant] ?? null;
    }

    /** How the template shows a value of this row. A part row, such as a fix reason, has no metric key and shows a decimal. */
    public function format(): string
    {
        $metric = Metric::tryFrom($this->key);

        return match (true) {
            null === $metric => 'decimal',
            Metric::Duration === $metric => 'minutes',
            default => match ($metric->valueType()) {
                MetricValueType::Money => 'currency',
                MetricValueType::Ratio, MetricValueType::Boolean => 'percent',
                MetricValueType::Tokens => 'integer',
                MetricValueType::Count, MetricValueType::Duration => 'decimal',
            },
        };
    }
}
