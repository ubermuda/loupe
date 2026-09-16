<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;

final readonly class FindSiteReviewCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(FindSiteReviewCommentCommand $command): ?SiteReviewComment
    {
        return $this->siteReviewComments->findOneByIdAndProjectId($command->id, (string) $command->project->id);
    }
}
