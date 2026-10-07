<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** The metrics an analytics query can read. The keys reach agents and URLs, so they stay stable. */
enum Metric: string
{
    case Cost = 'cost';
    case InputTokens = 'input-tokens';
    case OutputTokens = 'output-tokens';
    case CacheReadTokens = 'cache-read-tokens';
    case CacheWriteTokens = 'cache-write-tokens';
    case Duration = 'duration';
    case Runs = 'runs';
    case StopRate = 'stop-rate';
    case MergeRate = 'merge-rate';
    case FixRounds = 'fix-rounds';
    case HoursToMerge = 'hours-to-merge';
    case BucketTime = 'bucket-time';

    /**
     * The metrics whose key says all. Bucket time needs a bucket name too, so
     * a picker that lists keys alone leaves it out.
     *
     * @return list<self>
     */
    public static function standalone(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $metric): bool => self::BucketTime !== $metric));
    }

    /** @return list<MetricUnit> */
    public function units(): array
    {
        return match ($this) {
            self::Runs, self::MergeRate, self::FixRounds, self::HoursToMerge => [MetricUnit::Card],
            self::StopRate => [MetricUnit::Run],
            default => [MetricUnit::Run, MetricUnit::Card],
        };
    }

    public function valueType(): MetricValueType
    {
        return match ($this) {
            self::Cost => MetricValueType::Money,
            self::InputTokens, self::OutputTokens, self::CacheReadTokens, self::CacheWriteTokens => MetricValueType::Tokens,
            self::Duration, self::HoursToMerge, self::BucketTime => MetricValueType::Duration,
            self::Runs, self::FixRounds => MetricValueType::Count,
            self::StopRate, self::MergeRate => MetricValueType::Ratio,
        };
    }

    /** @return list<MetricStatistic> */
    public function statistics(): array
    {
        return MetricValueType::Ratio === $this->valueType()
            ? [MetricStatistic::Mean, MetricStatistic::Count]
            : MetricStatistic::cases();
    }

    /** @return list<MetricGroup> */
    public function groups(): array
    {
        return $this->isCardOutcome()
            ? [MetricGroup::Variant, MetricGroup::CardType, MetricGroup::None]
            : MetricGroup::cases();
    }

    /** A sum over the runs of a card gives its value on the card unit. */
    public function isAdditive(): bool
    {
        return !$this->isCardOutcome() && self::StopRate !== $this;
    }

    public function isCardOutcome(): bool
    {
        return \in_array($this, [self::MergeRate, self::FixRounds, self::HoursToMerge], true);
    }

    public function description(): string
    {
        return match ($this) {
            self::Cost => 'The cost in US dollars; unknown when a started run has no price.', // @translation-check-ignore
            self::InputTokens => 'The input tokens the model read.', // @translation-check-ignore
            self::OutputTokens => 'The output tokens the model wrote.', // @translation-check-ignore
            self::CacheReadTokens => 'The tokens the model read from its prompt cache.', // @translation-check-ignore
            self::CacheWriteTokens => 'The tokens the model wrote to its prompt cache.', // @translation-check-ignore
            self::Duration => 'The time from the start to the end of a run, in milliseconds.', // @translation-check-ignore
            self::Runs => 'The number of worker runs on a finished card.', // @translation-check-ignore
            self::StopRate => 'The share of runs that reached an outcome and blocked, failed, gave no result or gave up.', // @translation-check-ignore
            self::MergeRate => 'The share of finished cards whose pull request merged.', // @translation-check-ignore
            self::FixRounds => 'The number of fix rounds a finished card needed.', // @translation-check-ignore
            self::HoursToMerge => 'The hours from the first pull request opening to the last merge.', // @translation-check-ignore
            self::BucketTime => 'The time of the main-session tool calls of a run in one bucket of the project rules, in milliseconds. Name the bucket after a colon: bucket-time:<name>.', // @translation-check-ignore
        };
    }
}
