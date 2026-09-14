<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Reads one item with its asks. A reader session records its read on the item. */
final readonly class ShowInboxItemHandler
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ShowInboxItemCommand $command): InboxItemDetailView
    {
        $item = $command->item;
        $readerSessionId = $command->readerSessionId;
        if (null === $readerSessionId) {
            return new InboxItemDetailView($item, $this->inboxAsks->findHolding($item));
        }

        // Under the lock the ask closer takes, so the answer returned and the stamp
        // agree. A plain connection transaction, because a read must never flush.
        return $this->em->getConnection()->transactional(function () use ($item, $readerSessionId): InboxItemDetailView {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            // The caller loaded the item before the lock, and an answer can have landed since.
            $this->inboxItems->reloadChangeableColumns([$item]);
            $this->inboxAsks->recordRead($readerSessionId, [$item], new \DateTimeImmutable());

            return new InboxItemDetailView($item, $this->inboxAsks->findHolding($item));
        });
    }
}
