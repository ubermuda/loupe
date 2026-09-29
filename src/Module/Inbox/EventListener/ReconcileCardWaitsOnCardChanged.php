<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Inbox\Service\CardWaitTrigger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A link, an unlink, a move or a delete can each change the waits of the card. */
#[AsEventListener]
final readonly class ReconcileCardWaitsOnCardChanged
{
    public function __construct(
        private CardWaitTrigger $trigger,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        $this->trigger->forCards($event->projectId, [(string) $event->cardId]);
    }
}
