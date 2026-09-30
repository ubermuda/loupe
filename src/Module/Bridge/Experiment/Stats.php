<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use Random\Engine\Mt19937;
use Random\Randomizer;

final class Stats
{
    public const int MIN_FINISHED_CARDS = 5;

    private const float Z = 1.959964;

    private const float CLOSE_POINTS = 0.1;

    public static function wilson(int $successes, int $trials): ?Interval
    {
        if ($trials <= 0) {
            return null;
        }

        $point = $successes / $trials;
        $zz = self::Z ** 2;
        $denominator = 1 + $zz / $trials;
        $centre = ($point + $zz / (2 * $trials)) / $denominator;
        $margin = self::Z / $denominator * sqrt($point * (1 - $point) / $trials + $zz / (4 * $trials ** 2));

        // At 0 or all successes the exact bound is 0 or 1; the float sum misses it.
        return new Interval(
            0 === $successes ? 0.0 : max(0.0, $centre - $margin),
            $successes === $trials ? 1.0 : min(1.0, $centre + $margin),
            $point,
        );
    }

    /**
     * A percentile bootstrap. The seed fixes the resamples, so a report shows the same range on every load.
     *
     * @param list<float|int> $values
     */
    public static function bootstrapMean(array $values, string $seed, int $resamples = 2000): ?Interval
    {
        if ($resamples < 1) {
            throw new \InvalidArgumentException('A bootstrap needs at least one resample.');
        }

        $count = \count($values);
        if (0 === $count) {
            return null;
        }

        $randomizer = new Randomizer(new Mt19937(crc32($seed)));
        $means = [];
        for ($i = 0; $i < $resamples; ++$i) {
            $sum = 0;
            for ($j = 0; $j < $count; ++$j) {
                $sum += $values[$randomizer->getInt(0, $count - 1)];
            }
            $means[] = $sum / $count;
        }
        sort($means);

        $last = $resamples - 1;

        return new Interval(
            (float) $means[(int) floor(0.025 * $last)],
            (float) $means[(int) ceil(0.975 * $last)],
            array_sum($values) / $count,
        );
    }

    /**
     * Whether two arms give a clear answer: a real difference, or points too close to matter.
     */
    public static function isClear(Interval $a, Interval $b, int $finishedA, int $finishedB): bool
    {
        if ($finishedA < self::MIN_FINISHED_CARDS || $finishedB < self::MIN_FINISHED_CARDS) {
            return false;
        }

        if ($a->high < $b->low || $b->high < $a->low) {
            return true;
        }

        $larger = max(abs($a->point), abs($b->point));

        return 0.0 === $larger || abs($a->point - $b->point) <= self::CLOSE_POINTS * $larger;
    }
}
