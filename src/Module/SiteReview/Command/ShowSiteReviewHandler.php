<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\SiteReviewEventType;
use App\Outbox\Repository\OutboxEventRepository;

final readonly class ShowSiteReviewHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private OutboxEventRepository $outboxEvents,
    ) {
    }

    public function __invoke(ShowSiteReviewCommand $command): ShowSiteReviewView
    {
        return new ShowSiteReviewView(
            project: $command->project,
            comments: $this->siteReviewComments->findForProject($command->project),
            unsentCount: $this->outboxEvents->countUnsent($command->project, SiteReviewEventType::SUBMITTED),
        );
    }
}
