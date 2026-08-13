<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;

final readonly class ShowPendingCommentsHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(ShowPendingCommentsCommand $command): ShowPendingCommentsView
    {
        return new ShowPendingCommentsView(
            comments: $this->siteReviewComments->findPendingForProject($command->project),
        );
    }
}
