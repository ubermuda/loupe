<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;

/**
 * One project's site-review comments, narrowed to a status.
 *
 * A null status means every comment, which is a wider read than any single
 * status gives rather than a missing filter.
 */
final readonly class ListSiteReviewCommentsCommand
{
    public function __construct(
        public Project $project,
        public ?SiteReviewCommentStatus $status = null,
    ) {
    }
}
