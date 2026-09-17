<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Doctrine\SearchLanguage;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;

final readonly class UpdateProjectCommand
{
    public function __construct(
        public Project $project,
        /** @phpstan-var non-empty-string */
        public string $name,
        public ?string $domain,
        public SearchLanguage $searchLanguage,
        /** @phpstan-var ProjectEventType::ACTOR_* */
        public string $actor,
        public ?string $description = null,
    ) {
    }
}
