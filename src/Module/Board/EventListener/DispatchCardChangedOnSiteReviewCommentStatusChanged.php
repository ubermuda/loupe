<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A card face counts its pending comments, so a status change changes the face. */
#[AsEventListener]
final readonly class DispatchCardChangedOnSiteReviewCommentStatusChanged
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(SiteReviewCommentStatusChanged $event): void
    {
        $cardId = $this->cardSiteReviewComments->findOneByCommentId($event->commentId)?->card->id;
        if (null === $cardId) {
            return;
        }

        $this->events->dispatch(new CardChanged($event->projectId, $cardId, CardChanged::UPDATED, false));
    }
}
