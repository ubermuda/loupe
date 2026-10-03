<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class EvaluateCardsOnPullRequestStateChanged
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $pullRequest = $event->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        if (null === $forge) {
            return;
        }

        $this->trigger->forCards(array_map(
            static fn (CardPullRequest $link): string => (string) $link->card->id,
            $this->cardPullRequests->findForPullRequest(
                $pullRequest->project->id ?? throw new \LogicException('Project has no id.'),
                $forge,
                $pullRequest->repository,
                $pullRequest->number,
            ),
        ));
    }
}
