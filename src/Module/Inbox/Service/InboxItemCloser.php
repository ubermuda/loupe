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
    /** The refusal a caller gives when the item it acts on is already closed. */
    public const string ITEM_NOT_OPEN = 'inbox.item.error.not_open';

    /**
     * Returns false and changes nothing when the item is already closed, so a
     * listener inside another write never aborts it.
     */
    public function close(InboxItem $item, InboxItemState $state, ?string $note, \DateTimeImmutable $now): bool
    {
        if (InboxItemState::Open === $state) {
            throw new \LogicException('An item closes into a closed state.');
        }
        if (InboxItemState::Open !== $item->state) {
            return false;
        }

        $item->state = $state;
        $item->closeNote = $note;
        $item->closedAt = $now;
        $item->updatedAt = $now;

        return true;
    }
}
