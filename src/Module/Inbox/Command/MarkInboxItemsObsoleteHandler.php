<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\InboxOpenCountPublisher;

/**
 * Closes as obsolete every open item of the moved cards whose linked cards all
 * sit in terminal columns. An item with one unfinished card stays open. With
 * the inbox off it closes nothing, so a board write never closes an ask.
 *
 * The caller holds the transaction of the move and flushes it, so this neither
 * opens a transaction nor flushes. It records no audit entry, because a record
 * written inside that transaction would outlive a rollback.
 */
final readonly class MarkInboxItemsObsoleteHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxAvailability $inbox,
        private InboxOpenCountPublisher $openCount,
    ) {
    }

    /** @return list<InboxItem> the items it closed */
    public function __invoke(MarkInboxItemsObsoleteCommand $command): array
    {
        if ([] === $command->cardIds || !$this->inbox->isEnabled()) {
            return [];
        }

        $now = new \DateTimeImmutable();
        $closed = array_values(array_filter(
            $this->inboxItems->findOpenWithEveryCardFinished($command->cardIds),
            fn (InboxItem $item): bool => $this->closer->close($item, InboxItemState::Obsolete, null, $now),
        ));
        foreach ($closed as $item) {
            // Signalled before the move commits. The signal holds no count, so after a
            // rollback the pill only reloads the count as it stands.
            $this->openCount->countChanged($item->project);
        }

        return $closed;
    }
}
