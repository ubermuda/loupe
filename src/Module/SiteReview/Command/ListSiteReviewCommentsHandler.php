<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;

final readonly class ListSiteReviewCommentsHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(ListSiteReviewCommentsCommand $command): ListSiteReviewCommentsView
    {
        return new ListSiteReviewCommentsView(
            null === $command->status
                ? $this->siteReviewComments->findForProject($command->project)
                : $this->siteReviewComments->findForProjectWithStatus($command->project, $command->status),
        );
    }
}
