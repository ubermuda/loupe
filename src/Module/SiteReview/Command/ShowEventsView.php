<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowEventsView
{
    /** @param list<Project> $projects */
    public function __construct(
        public string $hubUrl,
        public string $jwt,
        public string $topic,
        public array $projects,
    ) {
    }
}
