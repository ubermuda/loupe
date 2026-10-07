<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\View\MetricsQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

final class MetricsQueryTest extends TestCase
{
    public function test_an_empty_query_gives_the_defaults_and_no_route_params(): void
    {
        $query = MetricsQuery::fromQuery(self::query([]));

        self::assertSame(MetricUnit::Card, $query->unit);
        self::assertSame(Metric::Cost, $query->metric);
        self::assertSame(MetricStatistic::Median, $query->statistic);
        self::assertSame(MetricGroup::None, $query->group);
        self::assertSame(MetricRange::NinetyDays, $query->range);
        self::assertSame(MetricBucket::Week, $query->bucket);
        self::assertSame([], $query->routeParams());
    }

    public function test_a_full_query_round_trips_through_the_route_params(): void
    {
        $params = ['unit' => 'run', 'metric' => 'duration', 'statistic' => 'p90', 'group' => 'model', 'range' => 'all', 'bucket' => 'day'];

        self::assertSame($params, MetricsQuery::fromQuery(self::query($params))->routeParams());
    }

    public function test_an_unknown_value_falls_back_to_the_default(): void
    {
        $query = MetricsQuery::fromQuery(self::query(['unit' => 'team', 'metric' => 'joy', 'statistic' => 'mode', 'group' => 'colour', 'range' => 'week', 'bucket' => 'year']));

        self::assertSame([], $query->routeParams());
    }

    public function test_a_value_the_metric_does_not_allow_falls_back_to_one_it_allows(): void
    {
        $query = MetricsQuery::fromQuery(self::query(['unit' => 'run', 'metric' => 'merge-rate', 'statistic' => 'median', 'group' => 'model']));

        self::assertSame(MetricUnit::Card, $query->unit);
        self::assertSame(MetricStatistic::Mean, $query->statistic);
        self::assertSame(MetricGroup::None, $query->group);

        $stopRate = MetricsQuery::fromQuery(self::query(['metric' => 'stop-rate']));
        self::assertSame(MetricUnit::Run, $stopRate->unit);
        self::assertSame(['unit' => 'run', 'metric' => 'stop-rate', 'statistic' => 'mean'], $stopRate->routeParams());
    }

    /** @return iterable<string, array{Metric}> */
    public static function metrics(): iterable
    {
        foreach (Metric::cases() as $metric) {
            yield $metric->value => [$metric];
        }
    }

    #[DataProvider('metrics')]
    public function test_every_metric_lands_on_a_combination_the_handler_accepts(Metric $metric): void
    {
        foreach (MetricUnit::cases() as $unit) {
            foreach (MetricStatistic::cases() as $statistic) {
                foreach (MetricGroup::cases() as $group) {
                    $query = MetricsQuery::fromQuery(self::query(['metric' => $metric->value, 'unit' => $unit->value, 'statistic' => $statistic->value, 'group' => $group->value]));

                    self::assertContains($query->unit, $metric->units());
                    self::assertContains($query->statistic, $metric->statistics());
                    self::assertContains($query->group, $metric->groups());
                }
            }
        }
    }

    /**
     * @param array<string, string> $values
     *
     * @return InputBag<string>
     */
    private static function query(array $values): InputBag
    {
        return new Request($values)->query;
    }
}
