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
        /** A partial update keeps each value it does not change, under the same lock as the write. */
        public bool $changeModel = true,
        public bool $changeEffort = true,
        public bool $changeCollectFullText = true,
        /**
         * Null clears the project list, so the instance list applies.
         *
         * @var list<string>|null
         */
        public ?array $subcommandPrograms = null,
        public bool $changeSubcommandPrograms = true,
    ) {
    }
}
