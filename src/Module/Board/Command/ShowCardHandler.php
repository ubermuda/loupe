<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewReplyRepository;
use App\Module\SiteReview\View\SiteReviewReplyThreads;

final readonly class ShowCardHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private SiteReviewReplyRepository $siteReviewReplies,
    ) {
    }

    public function __invoke(ShowCardCommand $command): CardView
    {
        $links = $this->cardSiteReviewComments->findForCard($command->card);

        return new CardView(
            $command->card,
            $links,
            new SiteReviewReplyThreads($this->siteReviewReplies->findForComments(array_map(
                static fn (CardSiteReviewComment $link): SiteReviewComment => $link->comment,
                $links,
            ))),
        );
    }
}
