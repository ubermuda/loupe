<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;

final readonly class ShowSiteReviewView
{
    /** @param list<SiteReviewComment> $comments */
    public function __construct(
        public Project $project,
        public array $comments,
        public int $unsentCount,
    ) {
    }
}
