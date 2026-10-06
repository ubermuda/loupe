<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricRowSource;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricStatistic;
use Psr\Clock\ClockInterface;

final readonly class MetricQueryHandler
{
    public const string UNIT_NOT_ALLOWED = 'bridge.metric.error.unit_not_allowed';

    public const string STATISTIC_NOT_ALLOWED = 'bridge.metric.error.statistic_not_allowed';

    public const string GROUP_NOT_ALLOWED = 'bridge.metric.error.group_not_allowed';

    public function __construct(
        private MetricRowSource $rows,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MetricQueryCommand $command): MetricQueryView
    {
        $metric = $command->metric;
        $errors = [];
        if (!\in_array($command->unit, $metric->units(), true)) {
            $errors['unit'] = self::UNIT_NOT_ALLOWED;
        }
        if (!\in_array($command->statistic, $metric->statistics(), true)) {
            $errors['statistic'] = self::STATISTIC_NOT_ALLOWED;
        }
        if (!\in_array($command->group, $metric->groups(), true)) {
            $errors['group'] = self::GROUP_NOT_ALLOWED;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $rows = $this->rows->rows($command->project, $command->unit, $metric, $command->group, $command->range->startFrom($this->clock->now()));

        /** @var array<string, array{group: ?string, rows: list<MetricRow>}> $byGroup */
        $byGroup = [];
        foreach ($rows as $row) {
            $key = null === $row->group ? '' : 'key:'.$row->group;
            $byGroup[$key]['group'] = $row->group;
            $byGroup[$key]['rows'][] = $row;
        }
        // The empty key sorts first, so the series with no group moves to the end.
        ksort($byGroup, \SORT_STRING);
        if (isset($byGroup[''])) {
            $none = $byGroup[''];
            unset($byGroup['']);
            $byGroup[''] = $none;
        }

        $series = [];
        foreach ($byGroup as $group) {
            $series[] = self::series($group['group'], $group['rows'], $command->statistic, $command->bucket);
        }

        return new MetricQueryView($command, $series);
    }

    /** @param list<MetricRow> $rows */
    private static function series(?string $group, array $rows, MetricStatistic $statistic, MetricBucket $bucket): MetricSeries
    {
        usort($rows, static fn (MetricRow $a, MetricRow $b): int => [$b->time, (string) $b->unitId] <=> [$a->time, (string) $a->unitId]);

        /** @var array<int, array{start: \DateTimeImmutable, rows: list<MetricRow>}> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $start = $bucket->startOf($row->time);
            $buckets[$start->getTimestamp()]['start'] = $start;
            $buckets[$start->getTimestamp()]['rows'][] = $row;
        }
        ksort($buckets);

        return new MetricSeries(
            $group,
            array_values(array_map(static fn (array $part): MetricPoint => self::point($part['start'], $part['rows'], $statistic), $buckets)),
            self::point(null, $rows, $statistic),
            $rows,
        );
    }

    /** @param list<MetricRow> $rows */
    private static function point(?\DateTimeImmutable $start, array $rows, MetricStatistic $statistic): MetricPoint
    {
        $values = array_values(array_filter(
            array_map(static fn (MetricRow $row): int|float|null => $row->value, $rows),
            static fn (int|float|null $value): bool => null !== $value,
        ));

        return new MetricPoint($start, $statistic->of($values), \count($values));
    }
}
