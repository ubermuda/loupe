<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;

/** One project's feedback, narrowed to a status. A null status reads every item. */
final readonly class ListFeedbackCommand
{
    public function __construct(
        public Project $project,
        public ?SiteReviewCommentStatus $status,
    ) {
    }
}
