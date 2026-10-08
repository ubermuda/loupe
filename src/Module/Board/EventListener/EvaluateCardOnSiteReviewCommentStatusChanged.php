<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A carried note that leaves pending changes the wanted site review check, so the engine reads the card again. */
#[AsEventListener]
final readonly class EvaluateCardOnSiteReviewCommentStatusChanged
{
    public function __construct(
        private CardEvaluations $evaluations,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    public function __invoke(SiteReviewCommentStatusChanged $event): void
    {
        if (!$this->evaluations->isOn()) {
            return;
        }

        $cardId = $this->cardSiteReviewComments->findOneByCommentId($event->commentId)?->card->id;
        if (null === $cardId) {
            return;
        }

        $this->evaluations->forCards([$cardId]);
    }
}
