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

    public function test_bucket_time_is_no_choice_of_the_page(): void
    {
        self::assertSame(Metric::Cost, MetricsQuery::fromQuery(self::query(['metric' => 'bucket-time']))->metric);
        self::assertNotContains(Metric::BucketTime, Metric::standalone());
    }

    public function test_a_bucket_key_reads_the_time_of_that_bucket_and_round_trips(): void
    {
        $params = ['unit' => 'run', 'metric' => 'bucket-time:git', 'statistic' => 'p90', 'group' => 'model', 'range' => 'all', 'bucket' => 'day'];
        $query = MetricsQuery::fromQuery(self::query($params));

        self::assertSame(Metric::BucketTime, $query->metric);
        self::assertSame('git', $query->bucketName);
        self::assertSame($params, $query->routeParams());
    }

    public function test_a_standalone_metric_has_no_bucket_name(): void
    {
        self::assertNull(MetricsQuery::fromQuery(self::query(['metric' => 'duration']))->bucketName);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBucketKeys(): iterable
    {
        yield 'no name' => ['bucket-time:'];
        yield 'upper case' => ['bucket-time:Git'];
        yield 'space' => ['bucket-time:a b'];
        yield 'too long' => ['bucket-time:'.str_repeat('a', 65)];
        yield 'name on a standalone metric' => ['cost:git'];
    }

    #[DataProvider('invalidBucketKeys')]
    public function test_an_invalid_bucket_key_falls_back_to_cost(string $metric): void
    {
        $query = MetricsQuery::fromQuery(self::query(['metric' => $metric]));

        self::assertSame(Metric::Cost, $query->metric);
        self::assertNull($query->bucketName);
        self::assertSame([], $query->routeParams());
    }

    public function test_a_bucket_key_takes_the_controls_that_bucket_time_allows(): void
    {
        foreach (MetricUnit::cases() as $unit) {
            foreach (MetricStatistic::cases() as $statistic) {
                foreach (MetricGroup::cases() as $group) {
                    $query = MetricsQuery::fromQuery(self::query(['metric' => 'bucket-time:git', 'unit' => $unit->value, 'statistic' => $statistic->value, 'group' => $group->value]));

                    self::assertContains($query->unit, Metric::BucketTime->units());
                    self::assertContains($query->statistic, Metric::BucketTime->statistics());
                    self::assertContains($query->group, Metric::BucketTime->groups());
                }
            }
        }
    }

    /** @return iterable<string, array{Metric}> */
    public static function metrics(): iterable
    {
        foreach (Metric::standalone() as $metric) {
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
