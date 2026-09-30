<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** A 95% range around a point estimate. */
final readonly class Interval
{
    public function __construct(
        public float $low,
        public float $high,
        public float $point,
    ) {
    }
}
