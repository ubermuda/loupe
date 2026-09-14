<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an owner's response closes an item, or changes a response
 * already given. Every change runs under a lock on the project, which the
 * writers of asks take too, so an ask cannot close between the check that an
 * answer is still editable and the write that changes it.
 */
final readonly class InboxItemCloser
{
    public const string ERROR_FINAL = 'inbox.item.error.final';
    public const string ERROR_CLOSED_BY_AGENT = 'inbox.item.error.closed_by_agent';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
    ) {
    }

    /**
     * Applies $respond to the item and closes it in $state, in one transaction.
     *
     * An open item always takes a response. A closed one takes a change only
     * while no closed ask holds it, because an agent may already act on the
     * answer that closed that ask.
     *
     * @param \Closure(InboxItem): void $respond writes the response fields
     *
     * @throws DomainErrors keyed by $errorField when the item no longer takes a response
     */
    public function close(InboxItem $item, InboxItemState $state, string $errorField, \Closure $respond): void
    {
        $refusal = $this->em->wrapInTransaction(function () use ($item, $state, $respond): ?string {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            // Read under the lock, so a withdraw or a response that landed since
            // the item was loaded counts. refresh() would also reload readonly
            // columns, which Doctrine refuses to overwrite.
            [$item->state, $item->closedAt] = $this->inboxItems->currentStateOf($item);

            $refusal = $this->refusal($item);
            if (null !== $refusal) {
                return $refusal;
            }

            $now = new \DateTimeImmutable();
            $respond($item);
            if (InboxItemState::Open === $item->state) {
                $item->closedAt = $now;
            }
            $item->state = $state;
            $item->updatedAt = $now;
            $this->em->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new DomainErrors([$errorField => $refusal]);
        }
    }

    /** Why the item takes no response now, or null when it does. */
    private function refusal(InboxItem $item): ?string
    {
        return match ($item->state) {
            InboxItemState::Open => null,
            InboxItemState::Withdrawn, InboxItemState::Obsolete => self::ERROR_CLOSED_BY_AGENT,
            InboxItemState::Answered, InboxItemState::Done, InboxItemState::Declined => [] === $this->inboxAsks->findItemIdsHeldByClosedAsks([$item])
                ? null
                : self::ERROR_FINAL,
        };
    }
}
