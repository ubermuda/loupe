<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Forge;
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

        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');
        $cardIds = [(string) $card->id => (string) $card->id];
        foreach ($this->cardPullRequests->findOpenGitHubForCard($card) as $pullRequest) {
            foreach ($this->cardPullRequests->findForPullRequest($projectId, Forge::GitHub, $pullRequest->repository, $pullRequest->number) as $link) {
                $cardIds[(string) $link->card->id] = (string) $link->card->id;
            }
        }

        $this->evaluations->forCards(array_values($cardIds));
    }
}
