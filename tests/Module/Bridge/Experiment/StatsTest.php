<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Experiment\Stats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    public function test_wilson_gives_the_known_range_for_eight_of_ten(): void
    {
        $interval = Stats::wilson(8, 10);

        self::assertNotNull($interval);
        self::assertEqualsWithDelta(0.4902, $interval->low, 0.0001);
        self::assertEqualsWithDelta(0.9433, $interval->high, 0.0001);
        self::assertEqualsWithDelta(0.8, $interval->point, 1e-12);
    }

    public function test_wilson_starts_at_zero_for_no_successes(): void
    {
        $interval = Stats::wilson(0, 10);

        self::assertNotNull($interval);
        self::assertSame(0.0, $interval->low);
        self::assertEqualsWithDelta(0.2775, $interval->high, 0.0001);
        self::assertSame(0.0, $interval->point);
    }

    public function test_wilson_ends_at_one_for_all_successes(): void
    {
        $interval = Stats::wilson(10, 10);

        self::assertNotNull($interval);
        self::assertEqualsWithDelta(0.7225, $interval->low, 0.0001);
        self::assertSame(1.0, $interval->high);
    }

    public function test_wilson_has_no_range_for_no_trials(): void
    {
        self::assertNull(Stats::wilson(0, 0));
    }

    public function test_bootstrap_gives_the_same_range_for_the_same_seed(): void
    {
        $values = [3, 7, 1, 9, 4, 6, 2, 8, 5, 10];

        self::assertEquals(Stats::bootstrapMean($values, 'seed-a'), Stats::bootstrapMean($values, 'seed-a'));
    }

    public function test_bootstrap_gives_another_range_for_another_seed(): void
    {
        $values = [3, 7, 1, 9, 4, 6, 2, 8, 5, 10];

        self::assertNotEquals(Stats::bootstrapMean($values, 'seed-a'), Stats::bootstrapMean($values, 'seed-b'));
    }

    public function test_bootstrap_range_contains_the_mean(): void
    {
        $values = [3, 7, 1, 9, 4, 6, 2, 8, 5, 10];

        $interval = Stats::bootstrapMean($values, 'seed-a');

        self::assertNotNull($interval);
        self::assertSame(5.5, $interval->point);
        self::assertLessThan(5.5, $interval->low);
        self::assertGreaterThan(5.5, $interval->high);
        self::assertGreaterThanOrEqual(1.0, $interval->low);
        self::assertLessThanOrEqual(10.0, $interval->high);
    }

    public function test_bootstrap_of_one_value_is_that_value(): void
    {
        self::assertEquals(new Interval(4.0, 4.0, 4.0), Stats::bootstrapMean([4], 'seed-a'));
    }

    public function test_bootstrap_refuses_no_resamples(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Stats::bootstrapMean([1, 2], 'seed-a', 0);
    }

    public function test_bootstrap_has_no_range_for_no_values(): void
    {
        self::assertNull(Stats::bootstrapMean([], 'seed-a'));
    }

    /**
     * @return iterable<string, array{Interval, Interval, int, int, bool}>
     */
    public static function clearCases(): iterable
    {
        $low = new Interval(0.1, 0.3, 0.2);
        $high = new Interval(0.6, 0.9, 0.75);
        $wide = new Interval(0.1, 0.9, 0.5);

        yield 'apart ranges' => [$low, $high, 5, 5, true];
        yield 'too few finished in a' => [$low, $high, 4, 5, false];
        yield 'too few finished in b' => [$low, $high, 5, 4, false];
        yield 'overlapping ranges, far points' => [$wide, $high, 5, 5, false];
        yield 'overlapping ranges, points within 10%' => [$wide, new Interval(0.2, 0.8, 0.46), 5, 5, true];
        yield 'overlapping ranges, points just past 10%' => [$wide, new Interval(0.2, 0.8, 0.449), 5, 5, false];
        yield 'overlapping ranges, both points zero' => [new Interval(0.0, 0.3, 0.0), new Interval(0.0, 0.4, 0.0), 5, 5, true];
        yield 'negative points within 10%' => [new Interval(-9.0, 1.0, -10.0), new Interval(-8.0, 0.0, -9.5), 5, 5, true];
        yield 'touching ranges overlap' => [new Interval(0.1, 0.5, 0.3), new Interval(0.5, 0.9, 0.7), 5, 5, false];
    }

    #[DataProvider('clearCases')]
    public function test_is_clear(Interval $a, Interval $b, int $finishedA, int $finishedB, bool $expected): void
    {
        self::assertSame($expected, Stats::isClear($a, $b, $finishedA, $finishedB));
    }
}
