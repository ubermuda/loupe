<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\CardWaitTrigger;
use App\Module\Workflow\Event\CardPaused;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A pause starts a wait. Its release dispatches CardChanged, which ends the wait. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnCardPaused
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(CardPaused $event): void
    {
        $this->trigger->forCards($event->projectId, [$event->cardId->toRfc4122()]);
    }
}
