<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunTiming;
use Symfony\Component\Validator\Constraints as Assert;

/** The tool time and the idle gaps of a run. A null value is unknown. */
final class WorkerRunTimingInput
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $toolTimeMs = null,

        #[Assert\PositiveOrZero]
        public ?int $idleGapMs = null,
    ) {
    }

    public function timing(): WorkerRunTiming
    {
        return new WorkerRunTiming($this->toolTimeMs, $this->idleGapMs);
    }
}
