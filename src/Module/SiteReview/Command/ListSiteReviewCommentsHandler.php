<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;
use App\Module\SiteReview\View\SiteReviewReplyThreads;

final readonly class ListSiteReviewCommentsHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private SiteReviewReplyRepository $siteReviewReplies,
    ) {
    }

    public function __invoke(ListSiteReviewCommentsCommand $command): ListSiteReviewCommentsView
    {
        $comments = null === $command->status
            ? $this->siteReviewComments->findForProject($command->project)
            : $this->siteReviewComments->findForProjectWithStatus($command->project, $command->status);

        return new ListSiteReviewCommentsView($comments, new SiteReviewReplyThreads($this->siteReviewReplies->findForComments($comments)));
    }
}
