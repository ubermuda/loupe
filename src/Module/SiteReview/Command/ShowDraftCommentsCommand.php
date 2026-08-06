<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowDraftCommentsCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
