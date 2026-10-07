<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Bridge\Metric\MetricRange;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Project\Entity\Project;

final readonly class StartAnalysisCommand
{
    public function __construct(
        public Project $project,
        public AnalysisTopic $topic,
        public MetricRange $range,
        public ?string $question = null,
        /** Null takes the model of the project settings. */
        public ?string $model = null,
        /** Null takes the effort of the project settings. */
        public ?string $effort = null,
        /** The experiment an experiment analysis compares. Any other topic ignores it. */
        public ?string $experiment = null,
    ) {
    }
}
