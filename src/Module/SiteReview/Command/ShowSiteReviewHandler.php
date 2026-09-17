<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;
use App\Module\SiteReview\View\SiteReviewReplyThreads;

final readonly class ShowSiteReviewHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private SiteReviewReplyRepository $siteReviewReplies,
    ) {
    }

    public function __invoke(ShowSiteReviewCommand $command): ShowSiteReviewView
    {
        $comments = $this->siteReviewComments->findForProject($command->project);

        return new ShowSiteReviewView(
            project: $command->project,
            comments: $comments,
            replies: new SiteReviewReplyThreads($this->siteReviewReplies->findForComments($comments)),
        );
    }
}
