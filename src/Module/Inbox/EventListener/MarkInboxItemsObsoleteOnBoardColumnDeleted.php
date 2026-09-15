<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Board\Event\BoardColumnDeleted;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteCommand;
use App\Module\Inbox\Command\MarkInboxItemsObsoleteHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A column delete moves its cards in bulk and dispatches no CardMoved, so the
 * moved cards reach the obsolete rule from here. It runs inside the transaction
 * of the delete, and the rule reads each card's new column itself.
 */
#[AsEventListener]
final readonly class MarkInboxItemsObsoleteOnBoardColumnDeleted
{
    public function __construct(
        private MarkInboxItemsObsoleteHandler $markObsolete,
    ) {
    }

    public function __invoke(BoardColumnDeleted $event): void
    {
        ($this->markObsolete)(new MarkInboxItemsObsoleteCommand($event->movedCardIds));
    }
}
