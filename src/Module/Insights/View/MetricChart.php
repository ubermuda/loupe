<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Bridge\Metric\Metric;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricValueType;

/**
 * The statistic of each bucket as bars on a time axis, laid out in the units
 * of an SVG view box. The series of a bucket stand side by side, because a
 * stack of medians means nothing. Twig only prints these numbers.
 */
final readonly class MetricChart
{
    public const int WIDTH = 960;
    public const int HEIGHT = 280;
    public const int PLOT_LEFT = 64;
    public const int PLOT_RIGHT = 936;
    public const int PLOT_TOP = 16;
    public const int BASELINE = 248;
    /** The palette holds eight colours. With more than eight series, the first seven keep theirs and the rest share a grey. */
    public const int PALETTE_SIZE = 8;

    private const float MAX_BAR_WIDTH = 48.0;
    /** The part of a bucket that its bars fill. */
    private const float BAR_SHARE = 0.8;
    private const float GAP = 2.0;
    private const float MIN_BAR_HEIGHT = 1.0;
    private const float RADIUS = 4.0;
    private const int TARGET_TICKS = 4;
    private const int MAX_TIME_TICKS = 8;

    /**
     * @param list<MetricChartBucket> $buckets oldest first
     * @param list<MetricChartSeries> $series
     * @param list<MetricChartTick>   $yTicks
     * @param list<MetricChartTick>   $xTicks
     */
    public function __construct(
        public Metric $metric,
        public MetricStatistic $statistic,
        public MetricBucket $bucket,
        public array $buckets,
        public array $series,
        public array $yTicks,
        /** The decimals of a dollar label on the value axis. */
        public int $moneyDecimals,
        public array $xTicks,
        /** True when the time axis spans more than a year, so a label needs its year. */
        public bool $longSpan,
    ) {
    }

    /**
     * Null when no series has a bucket.
     *
     * @param list<MetricSeries> $series
     */
    public static function build(Metric $metric, MetricStatistic $statistic, MetricBucket $bucket, array $series): ?self
    {
        $starts = [];
        $values = [];
        foreach ($series as $item) {
            foreach ($item->points as $point) {
                if (null !== $point->start) {
                    $starts[$point->start->getTimestamp()] = $point->start;
                }
                if (null !== $point->value) {
                    $values[] = (float) $point->value;
                }
            }
        }
        if ([] === $starts) {
            return null;
        }
        ksort($starts);
        $starts = array_values($starts);
        $index = array_flip(array_map(static fn (\DateTimeImmutable $start): int => $start->getTimestamp(), $starts));

        [$top, $step] = self::axis($metric, $statistic, $values);
        $plotHeight = self::BASELINE - self::PLOT_TOP;
        $bucketWidth = (self::PLOT_RIGHT - self::PLOT_LEFT) / \count($starts);
        $seriesCount = \count($series);
        $slotWidth = $bucketWidth * self::BAR_SHARE / $seriesCount;
        $gap = min(self::GAP, $slotWidth / 4);
        $barWidth = min(self::MAX_BAR_WIDTH, $slotWidth - $gap);
        $groupOffset = ($bucketWidth - ($seriesCount * $barWidth + ($seriesCount - 1) * $gap)) / 2;

        $chartSeries = [];
        $named = $seriesCount > self::PALETTE_SIZE ? self::PALETTE_SIZE - 1 : self::PALETTE_SIZE;
        foreach ($series as $position => $item) {
            $chartSeries[] = new MetricChartSeries($item->group, $position < $named ? $position + 1 : 0, $position);
        }

        $bars = array_fill(0, \count($starts), []);
        foreach ($series as $position => $item) {
            foreach ($item->points as $point) {
                if (null === $point->start || null === $point->value) {
                    continue;
                }
                $bucketIndex = $index[$point->start->getTimestamp()];
                $x = self::PLOT_LEFT + $bucketIndex * $bucketWidth + $groupOffset + $position * ($barWidth + $gap);
                $height = $point->value > 0 ? min($plotHeight, max(self::MIN_BAR_HEIGHT, $point->value / $top * $plotHeight)) : 0.0;
                $bars[$bucketIndex][] = new MetricChartBar(
                    series: $chartSeries[$position],
                    point: $point,
                    x: round($x, 2),
                    width: round($barWidth, 2),
                    top: round(self::BASELINE - $height, 2),
                    path: $height > 0 ? self::path($x, self::BASELINE - $height, $barWidth, $height) : '',
                );
            }
        }

        $buckets = [];
        foreach ($starts as $bucketIndex => $start) {
            usort($bars[$bucketIndex], static fn (MetricChartBar $left, MetricChartBar $right): int => $left->x <=> $right->x);
            $buckets[] = new MetricChartBucket($start, round(self::PLOT_LEFT + ($bucketIndex + 0.5) * $bucketWidth, 2), $bars[$bucketIndex]);
        }

        $yTicks = [];
        $tickCount = (int) round($top / $step);
        for ($tick = 0; $tick <= $tickCount; ++$tick) {
            $value = round($tick * $step, 10);
            $yTicks[] = new MetricChartTick(round(self::BASELINE - $value / $top * $plotHeight, 2), $value);
        }

        $tickStep = self::tickStep($bucket, \count($buckets));
        $xTicks = [];
        foreach ($buckets as $bucketIndex => $item) {
            if (0 === $bucketIndex % $tickStep) {
                $xTicks[] = new MetricChartTick($item->centre, start: $item->start);
            }
        }

        return new self(
            metric: $metric,
            statistic: $statistic,
            bucket: $bucket,
            buckets: $buckets,
            series: $chartSeries,
            yTicks: $yTicks,
            moneyDecimals: $step >= 1 ? 0 : max(2, (int) ceil(-log10($step) - 1e-9)),
            xTicks: $xTicks,
            longSpan: (int) $starts[0]->diff($starts[\count($starts) - 1])->format('%a') > 366,
        );
    }

    /** A legend names the series when there is more than one. */
    public function hasLegend(): bool
    {
        return \count($this->series) > 1;
    }

    /**
     * The top of the value axis and its step. A count scales as a count. A ratio
     * tops at 100%. Another metric tops at a floor of its own, so tiny values still read.
     *
     * @param list<float> $values
     *
     * @return array{float, float}
     */
    private static function axis(Metric $metric, MetricStatistic $statistic, array $values): array
    {
        $type = MetricStatistic::Count === $statistic ? MetricValueType::Count : $metric->valueType();
        if (MetricValueType::Ratio === $type) {
            return [1.0, 0.25];
        }

        $floor = match ($type) {
            MetricValueType::Money => 0.04,
            MetricValueType::Duration => Metric::HoursToMerge === $metric ? 1.0 : 1000.0,
            default => (float) self::TARGET_TICKS,
        };
        $max = max($floor, ...$values);
        $step = self::niceStep($max / self::TARGET_TICKS);

        return [ceil($max / $step - 1e-9) * $step, $step];
    }

    /** A step of 1, 2 or 5 times a power of ten. */
    private static function niceStep(float $raw): float
    {
        $magnitude = 10 ** floor(log10($raw));
        $fraction = $raw / $magnitude;
        $nice = match (true) {
            $fraction <= 1 + 1e-9 => 1,
            $fraction <= 2 + 1e-9 => 2,
            $fraction <= 5 + 1e-9 => 5,
            default => 10,
        };

        return round($nice * $magnitude, 10);
    }

    /** How many buckets one label of the time axis covers. */
    private static function tickStep(MetricBucket $bucket, int $bucketCount): int
    {
        $steps = match ($bucket) {
            MetricBucket::Day => [1, 2, 7, 14, 30, 61, 91, 182, 365],
            MetricBucket::Week => [1, 2, 4, 8, 13, 26, 52],
            MetricBucket::Month => [1, 2, 3, 6, 12, 24],
        };
        foreach ($steps as $step) {
            if (ceil($bucketCount / $step) <= self::MAX_TIME_TICKS) {
                return $step;
            }
        }

        return (int) ceil($bucketCount / self::MAX_TIME_TICKS);
    }

    /** A rectangle with its top corners rounded. The baseline end stays square. */
    private static function path(float $x, float $top, float $width, float $height): string
    {
        $radius = min(self::RADIUS, $width / 2, $height);
        $right = $x + $width;
        $bottom = $top + $height;

        return \sprintf(
            'M%s %sV%sQ%s %s %s %sH%sQ%s %s %s %sV%sZ',
            self::number($x),
            self::number($bottom),
            self::number($top + $radius),
            self::number($x),
            self::number($top),
            self::number($x + $radius),
            self::number($top),
            self::number($right - $radius),
            self::number($right),
            self::number($top),
            self::number($right),
            self::number($top + $radius),
            self::number($bottom),
        );
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
