<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Inbox\Service\CardWaitTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A new state of a GitHub pull request can start or end a wait on each card that links it. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnPullRequestStateChanged
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $pullRequest = $event->pullRequest;
        if (Forge::GitHub !== Forge::tryFrom($pullRequest->forge)) {
            return;
        }

        $projectId = $pullRequest->project->id ?? throw new \LogicException('Project has no id.');
        $this->trigger->forCards($projectId, array_map(
            static fn (CardPullRequest $link): string => (string) $link->card->id,
            $this->cardPullRequests->findForPullRequest($projectId, Forge::GitHub, $pullRequest->repository, $pullRequest->number),
        ));
    }
}
