<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardTypeCatalog;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A pull request changes the facts of its cards, of the epic of a child, and of the children of an epic. */
#[AsEventListener]
final readonly class EvaluateCardsOnPullRequestStateChanged
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardRepository $cards,
        private EvaluationTrigger $trigger,
        private CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(PullRequestStateChanged $event): void
    {
        $pullRequest = $event->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        if (null === $forge) {
            return;
        }

        $cards = [];
        foreach ($this->cardPullRequests->findForPullRequest(
            $pullRequest->project->id ?? throw new \LogicException('Project has no id.'),
            $forge,
            $pullRequest->repository,
            $pullRequest->number,
        ) as $link) {
            $cards[] = $link->card;
        }
        $types = $this->catalog->forProject($pullRequest->project);
        $epics = array_values(array_filter($cards, static fn (Card $card): bool => $types->get($card->type)->children));

        $this->trigger->forCards(array_values(array_filter(
            [
                ...array_map(static fn (Card $card) => $card->id, $cards),
                ...array_map(static fn (Card $card) => $card->parent?->id, $cards),
                ...array_map(static fn (Card $child) => $child->id, array_merge(...array_values($this->cards->findChildrenOfCards($epics)))),
            ],
            static fn ($id): bool => null !== $id,
        )));
    }
}
