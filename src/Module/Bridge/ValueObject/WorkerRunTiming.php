<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** The tool time and the idle gaps of a run, as the bridge measured them. A null value is unknown. */
final readonly class WorkerRunTiming
{
    public function __construct(
        public ?int $toolTimeMs,
        public ?int $idleGapMs,
    ) {
    }
}
