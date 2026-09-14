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
 * already given. It runs under a lock on the project row. Every writer of asks
 * MUST take the same lock, or an ask can close between the check that an
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
     * @param \Closure(InboxItem): (array<string, string>|null) $respond validates against the item as
     *                                                                   stored, then writes the response
     *                                                                   fields; it returns field errors
     *                                                                   to refuse, or null
     *
     * @throws DomainErrors keyed by $errorField, or by the fields $respond names, when the item takes no such response
     */
    public function close(InboxItem $item, InboxItemState $state, string $errorField, \Closure $respond): void
    {
        $errors = $this->em->wrapInTransaction(function () use ($item, $state, $errorField, $respond): ?array {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            // Read under the lock, so a response or an agent's change that landed
            // since the item was loaded counts. refresh() would also reload
            // readonly columns, which Doctrine refuses to overwrite.
            $this->inboxItems->reloadMutableColumns($item);

            $refusal = $this->refusal($item);
            if (null !== $refusal) {
                return [$errorField => $refusal];
            }

            $wasOpen = InboxItemState::Open === $item->state;
            $errors = $respond($item);
            if (null !== $errors && [] !== $errors) {
                return $errors;
            }

            $now = new \DateTimeImmutable();
            if ($wasOpen) {
                $item->closedAt = $now;
            }
            $item->state = $state;
            $item->updatedAt = $now;
            // The flush that ends the transaction writes only what differs from
            // the copy Doctrine loaded, so it would miss a field cleared back to
            // the value that copy held.
            $this->inboxItems->writeResponse($item);

            return null;
        });

        if (null !== $errors) {
            throw new DomainErrors($errors);
        }
    }

    /** Why the item takes no response now, or null when it does. */
    private function refusal(InboxItem $item): ?string
    {
        // The two calls that ignore the asks settle an open item and an agent's
        // close with no query. Only a response the owner gave needs the asks.
        if ($item->state->acceptsResponse(heldByClosedAsk: true)) {
            return null;
        }
        if (!$item->state->acceptsResponse(heldByClosedAsk: false)) {
            return self::ERROR_CLOSED_BY_AGENT;
        }

        return $item->state->acceptsResponse([] !== $this->inboxAsks->findItemIdsHeldByClosedAsks([$item])) ? null : self::ERROR_FINAL;
    }
}
