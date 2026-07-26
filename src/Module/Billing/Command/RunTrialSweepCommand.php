<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

final readonly class RunTrialSweepCommand
{
    public function __construct(
        public \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {
    }
}
