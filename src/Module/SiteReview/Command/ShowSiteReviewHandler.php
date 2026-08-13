<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;

final readonly class ShowSiteReviewHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private SiteReviewEventRepository $siteReviewEvents,
    ) {
    }

    public function __invoke(ShowSiteReviewCommand $command): ShowSiteReviewView
    {
        return new ShowSiteReviewView(
            project: $command->project,
            comments: $this->siteReviewComments->findForProject($command->project),
            unsentCount: $this->siteReviewEvents->countUnsent($command->project),
        );
    }
}
