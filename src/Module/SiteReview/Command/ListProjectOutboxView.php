<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;

final readonly class ListProjectOutboxView
{
    /** @param list<SiteReviewEvent> $events */
    public function __construct(
        public Project $project,
        public array $events,
    ) {
    }
}
