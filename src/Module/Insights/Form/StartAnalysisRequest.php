<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\AnalysisTopic;
use Symfony\Component\Validator\Constraints as Assert;

class StartAnalysisRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?AnalysisTopic $topic = AnalysisTopic::Cost,

        #[Assert\NotNull]
        public ?MetricRange $range = MetricRange::ThirtyDays,
        /** Null takes the project default. */
        public ?string $model = null,
        /** Null takes the project default. */
        public ?string $effort = null,
    ) {
    }
}
