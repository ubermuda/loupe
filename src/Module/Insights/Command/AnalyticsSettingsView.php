<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

/** The values the project sets, beside the values an analysis takes when it names none. */
final readonly class AnalyticsSettingsView
{
    public function __construct(
        public Project $project,
        public ?string $defaultModel,
        public ?string $defaultEffort,
        public bool $collectFullText,
        public string $model,
        public string $effort,
    ) {
    }
}
