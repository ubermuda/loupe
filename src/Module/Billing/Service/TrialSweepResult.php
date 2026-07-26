<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

final readonly class TrialSweepResult
{
    public function __construct(
        public int $disabled = 0,
        public int $churnedSurveys = 0,
        public int $subscriberSurveys = 0,
        public int $cancelSurveys = 0,
        public int $failed = 0,
    ) {
    }
}
