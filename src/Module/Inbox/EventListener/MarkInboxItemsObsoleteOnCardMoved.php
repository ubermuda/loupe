<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\CardMoved;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteCommand;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * An item waits on the work of its cards, so it has nothing left to wait for
 * once they are all finished. It runs inside the transaction of the move.
 */
#[AsEventListener]
final readonly class MarkInboxItemsObsoleteOnCardMoved
{
    public function __construct(
        private MarkInboxItemsObsoleteHandler $markObsolete,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        if (!$event->card->column->terminal) {
            return;
        }

        ($this->markObsolete)(new MarkInboxItemsObsoleteCommand([(string) $event->card->id]));
    }
}
