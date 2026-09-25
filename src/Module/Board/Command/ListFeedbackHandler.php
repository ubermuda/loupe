<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Command\ListSiteReviewCommentsCommand;
use App\Module\SiteReview\Command\ListSiteReviewCommentsHandler;

final readonly class ListFeedbackHandler
{
    public function __construct(
        private ListSiteReviewCommentsHandler $listComments,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    public function __invoke(ListFeedbackCommand $command): ListFeedbackView
    {
        $feedback = ($this->listComments)(new ListSiteReviewCommentsCommand($command->project, $command->status))->comments;

        return new ListFeedbackView($feedback, $this->cardSiteReviewComments->findForComments($feedback));
    }
}
