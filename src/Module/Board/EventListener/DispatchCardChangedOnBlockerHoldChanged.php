<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Workflow\Contract\BlockerHoldChanged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A move rule that an open blocker starts or stops holding moves the card into or out of the Held state. */
#[AsEventListener]
final readonly class DispatchCardChangedOnBlockerHoldChanged
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(BlockerHoldChanged $event): void
    {
        $this->events->dispatch(new CardChanged($event->projectId, $event->cardId, CardChanged::UPDATED, false));
    }
}
