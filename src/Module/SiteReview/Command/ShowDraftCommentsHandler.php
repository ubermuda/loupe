<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;

final readonly class ShowDraftCommentsHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(ShowDraftCommentsCommand $command): ShowDraftCommentsView
    {
        return new ShowDraftCommentsView(
            comments: $this->siteReviewComments->findDraftForProject($command->project),
        );
    }
}
