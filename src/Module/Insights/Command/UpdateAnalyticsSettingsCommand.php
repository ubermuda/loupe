<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Project\Entity\Project;

final readonly class UpdateAnalyticsSettingsCommand
{
    public function __construct(
        public Project $project,
        /** Null clears the project value, so the instance flag applies. */
        public ?string $model,
        /** Null clears the project value, so the instance flag applies. */
        public ?string $effort,
        public bool $collectFullText,
    ) {
    }
}
