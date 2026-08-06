<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class ListSitesView
{
    /** @param list<Project> $sites */
    public function __construct(
        public array $sites,
    ) {
    }
}
