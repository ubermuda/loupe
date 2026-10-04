<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Workflow\Service\EvaluationTrigger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The card face shows its work requests, so the board updates whether or not the engine runs. */
#[AsEventListener]
final readonly class EvaluateCardOnWorkRequestChanged
{
    public function __construct(
        private EventDispatcherInterface $events,
        private EvaluationTrigger $trigger,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $this->events->dispatch(new CardChanged($event->projectId, $event->cardId, CardChanged::UPDATED, false));
        $this->trigger->forCards([$event->cardId]);
    }
}
