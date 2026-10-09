<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A carried note that leaves pending changes the wanted site review check, so the engine reads every card on the open pull requests of its card. */
#[AsEventListener]
final readonly class EvaluateCardOnSiteReviewCommentStatusChanged
{
    public function __construct(
        private CardEvaluations $evaluations,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardPullRequestRepository $cardPullRequests,
    ) {
    }

    public function __invoke(SiteReviewCommentStatusChanged $event): void
    {
        if (!$this->evaluations->isOn()) {
            return;
        }

        $card = $this->cardSiteReviewComments->findOneByCommentId($event->commentId)?->card;
        if (null === $card) {
            return;
        }

        $this->evaluations->forCards($this->cardPullRequests->findCardIdsSharingOpenPullRequests($card));
    }
}
