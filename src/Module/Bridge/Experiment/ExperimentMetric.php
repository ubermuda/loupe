<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One row of the comparison: a range per variant, and whether the first two variants give a clear answer. */
final readonly class ExperimentMetric
{
    public const string MERGE_RATE = 'merge-rate';

    public const string STOP_RATE = 'stop-rate';

    public const string FIX_ROUNDS = 'fix-rounds';

    public const string COST = 'cost';

    public const string OUTPUT_TOKENS = 'output-tokens';

    public const string HOURS_TO_MERGE = 'hours-to-merge';

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
}
