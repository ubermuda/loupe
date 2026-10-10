<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardPullRequestsChanged;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The site review check of a pull request counts every card that links it, so a link change moves the check of the other cards. */
#[AsEventListener]
final readonly class EvaluateCardsOnCardPullRequestsChanged
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardEvaluations $trigger,
    ) {
    }

    public function __invoke(CardPullRequestsChanged $event): void
    {
        if (!$this->trigger->isOn()) {
            return;
        }

        $cardIds = [(string) $event->cardId => $event->cardId];
        foreach ($event->references as [$repository, $number]) {
            foreach ($this->cardPullRequests->findForPullRequest($event->projectId, Forge::GitHub, $repository, $number) as $link) {
                $id = $link->card->id;
                if (null !== $id) {
                    $cardIds[(string) $id] = $id;
                }
            }
        }

        $this->trigger->forCards(array_values($cardIds));
    }
}
