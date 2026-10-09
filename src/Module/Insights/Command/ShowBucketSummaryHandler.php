<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Bridge\Service\BucketRule;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\View\MetricsQuery;
use Psr\Clock\ClockInterface;

final readonly class ShowBucketSummaryHandler
{
    public function __construct(
        private InsightsBucketRuleRepository $insightsBucketRules,
        private MetricRowSource $metricRows,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ShowBucketSummaryCommand $command): ShowBucketSummaryView
    {
        $bucketTimes = $this->metricRows->bucketTimes($command->project, $command->range->startFrom($this->clock->now()));

        $named = array_map(static fn (InsightsBucketRule $rule): string => $rule->bucket, $this->insightsBucketRules->findOrdered($command->project));
        $named[] = BucketRule::FALLBACK;
        $named = array_values(array_unique($named));
        $timed = [];
        foreach ($bucketTimes->times as $runTimes) {
            foreach (array_keys($runTimes) as $name) {
                $timed[(string) $name] = true;
            }
        }
        $others = array_values(array_diff(array_map(strval(...), array_keys($timed)), $named));
        sort($others, \SORT_STRING);

        $figures = [];
        foreach ([...$named, ...$others] as $name) {
            // A run with data and no time in the bucket spent 0 ms there.
            $values = array_values(array_map(static fn (array $runTimes): int => $runTimes[$name] ?? 0, $bucketTimes->times));
            $figures[$name] = [MetricStatistic::Sum->of($values) ?? 0, MetricStatistic::Median->of($values)];
        }
        $grandTotal = array_sum(array_column($figures, 0));

        $rows = [];
        foreach ($figures as $name => [$total, $median]) {
            $name = (string) $name;
            $rows[] = new BucketSummaryRow(
                $name,
                $total,
                $median,
                0 < $grandTotal ? $total / $grandTotal : null,
                0 < $total
                    ? new MetricsQuery(unit: MetricUnit::Run, metric: Metric::BucketTime, statistic: MetricStatistic::Median, range: $command->range, bucketName: $name)->routeParams()
                    : null,
            );
        }

        return new ShowBucketSummaryView($command->project, $command->range, $rows, \count($bucketTimes->times), $bucketTimes->runs);
    }
}
