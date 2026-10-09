<?php

declare(strict_types=1);

namespace App\Module\Bridge\Metric;

/** The bucket times of the closed runs of a project in a range. */
final readonly class BucketTimes
{
    /**
     * @param int                                   $runs  the closed runs in the range, with bucket data or not
     * @param array<string, array<int|string, int>> $times run id => bucket name => milliseconds, for the runs with bucket data alone; a numeric name is an int key
     */
    public function __construct(
        public int $runs,
        public array $times,
    ) {
    }
}
