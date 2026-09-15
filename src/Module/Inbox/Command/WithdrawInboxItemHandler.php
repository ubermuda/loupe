<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxLimits;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxItemCloser;
use App\Module\Inbox\Service\InboxOpenCountPublisher;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Closes an open item as withdrawn, with the reason in its close note. */
final readonly class WithdrawInboxItemHandler
{
    public const string REASON_BLANK = 'inbox.item.error.withdraw_reason_blank';
    public const string REASON_TOO_LONG = 'inbox.item.error.withdraw_reason_too_long';

    public function __construct(
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private InboxOpenCountPublisher $openCount,
    ) {
    }

    public function __invoke(WithdrawInboxItemCommand $command): InboxItem
    {
        if (mb_strlen($command->reason) > InboxLimits::MAX_WITHDRAW_REASON_LENGTH) {
            throw new DomainErrors(['reason' => self::REASON_TOO_LONG]);
        }
        $reason = trim($command->reason);
        if ('' === $reason) {
            throw new DomainErrors(['reason' => self::REASON_BLANK]);
        }

        $item = $command->item;
        $refusal = $this->em->wrapInTransaction(function () use ($item, $reason): ?string {
            // The project first, as every item writer takes it, then the state as
            // stored, so an answer that lands first is not overwritten.
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            if (InboxItemState::Open !== $this->inboxItems->lockedState($item)
                || !$this->closer->close($item, InboxItemState::Withdrawn, $reason, new \DateTimeImmutable())) {
                return InboxItemCloser::ITEM_NOT_OPEN;
            }
            $this->em->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new DomainErrors(['itemId' => $refusal]);
        }
        $this->openCount->countChanged($item->project);

        $this->auditor->record(
            'inbox.item_withdrawn',
            AuditOutcome::Success,
            [
                'itemId' => (string) $item->id,
                'itemNumber' => $item->number,
                'projectId' => (string) $item->project->id,
            ],
            new AuditSubject('inbox_item', (string) $item->id),
        );

        return $item;
    }
}
