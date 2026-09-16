<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class FindSiteReviewCommentCommand
{
    public function __construct(
        public string $id,
        public Project $project,
    ) {
    }
}
