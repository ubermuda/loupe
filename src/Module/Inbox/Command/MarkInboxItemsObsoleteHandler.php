<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Closes as obsolete every open item whose linked cards are all in terminal
 * columns, once one of those cards is. An item with one unfinished card stays open.
 *
 * It records no audit entry: it runs inside the transaction of the card move,
 * and a record written there would outlive a rollback.
 */
final readonly class MarkInboxItemsObsoleteHandler
{
    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private EntityManagerInterface $em,
    ) {
    }

    /** @return list<InboxItem> the items it closed */
    public function __invoke(MarkInboxItemsObsoleteCommand $command): array
    {
        if (!$command->card->column->terminal) {
            return [];
        }

        return $this->em->wrapInTransaction(function () use ($command): array {
            $now = new \DateTimeImmutable();
            $closed = $this->inboxItems->findOpenWithEveryCardFinished($command->card);
            foreach ($closed as $item) {
                $this->closer->close($item, InboxItemState::Obsolete, null, $now);
            }
            $this->em->flush();

            return $closed;
        });
    }
}
