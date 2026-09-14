<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;

/**
 * The one place an open item closes. It changes the item and does not flush,
 * so the caller decides the transaction.
 */
final readonly class InboxItemCloser
{
    public function close(InboxItem $item, InboxItemState $state, ?string $note, \DateTimeImmutable $now): void
    {
        if (InboxItemState::Open === $state) {
            throw new \LogicException('An item closes into a closed state.');
        }
        if (InboxItemState::Open !== $item->state) {
            throw new \LogicException('Only an open item closes.');
        }

        $item->state = $state;
        $item->closeNote = $note;
        $item->closedAt = $now;
        $item->updatedAt = $now;
    }
}
