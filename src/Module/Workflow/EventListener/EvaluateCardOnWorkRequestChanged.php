<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The card face shows its work requests, so the board updates whether or not the engine runs.
 * The children of the card read its requested work as the work of their parent, so they evaluate again too.
 */
#[AsEventListener]
final readonly class EvaluateCardOnWorkRequestChanged
{
    public function __construct(
        private EventDispatcherInterface $events,
        private CardRepository $cards,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $cardId = $event->cardId();
        if (null === $cardId) {
            return;
        }
        $this->events->dispatch(new CardChanged($event->projectId, $cardId, CardChanged::UPDATED, false));
        $this->trigger->forCards([$cardId, ...$this->cards->findChildIds($cardId)]);
    }
}
