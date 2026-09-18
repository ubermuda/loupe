<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place an item closes, or an owner changes a response already given.
 * Every write runs under a lock on the project row. Every writer of items or
 * asks MUST take that lock first, or an ask can close between the check that an
 * answer is still editable and the write that changes it.
 */
final readonly class InboxItemCloser
{
    public const string ERROR_FINAL = 'inbox.item.error.final';
    public const string ERROR_CLOSED_BY_AGENT = 'inbox.item.error.closed_by_agent';

    /** The refusal a caller gives when the item it acts on is already closed. */
    public const string ITEM_NOT_OPEN = 'inbox.item.error.not_open';

    public function __construct(
        private EntityManagerInterface $em,
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private InboxAskCloser $askCloser,
        private InboxOpenCountPublisher $openCount,
    ) {
    }

    /**
     * Applies the owner's $respond to the item and closes it in $state, in a
     * transaction of its own that locks the project.
     *
     * An open item always takes a response. A closed one takes a change only
     * while no closed ask holds it, because an agent may already act on the
     * answer that closed that ask. The close of an open item closes the asks
     * it was the last open blocking item of, as the owner.
     *
     * @param \Closure(InboxItem): (array<string, string>|null) $respond validates against the item as
     *                                                                   stored, then writes the response
     *                                                                   fields; it returns field errors
     *                                                                   to refuse, or null
     *
     * @throws DomainErrors keyed by $errorField, or by the fields $respond names, when the item takes no such response
     */
    public function respond(InboxItem $item, InboxItemState $state, string $errorField, \Closure $respond): void
    {
        self::assertClosed($state);

        $outcome = $this->em->wrapInTransaction(function () use ($item, $state, $errorField, $respond): array|bool {
            $this->em->lock($item->project, LockMode::PESSIMISTIC_WRITE);
            // Read under the lock, so a response or an agent's change that landed
            // since the item was loaded counts. refresh() would also reload
            // readonly columns, which Doctrine refuses to overwrite.
            $this->inboxItems->reloadChangeableColumns([$item]);

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
            if ($wasOpen) {
                $this->askCloser->closeAsksHolding($item, InboxEventType::ACTOR_HUMAN, $now);
            }

            return $wasOpen;
        });

        if (\is_array($outcome)) {
            throw new DomainErrors($outcome);
        }
        if ($outcome) {
            $this->openCount->countChanged($item->project);
        }
    }

    /**
     * The caller holds the project lock and transaction, and flushes afterward.
     *
     * @param InboxEventType::ACTOR_* $actor
     */
    public function close(InboxItem $item, InboxItemState $state, ?string $note, \DateTimeImmutable $now, string $actor = InboxEventType::ACTOR_AGENT): bool
    {
        self::assertClosed($state);
        if (InboxItemState::Open !== $item->state) {
            return false;
        }

        $item->state = $state;
        $item->closeNote = $note;
        $item->closedAt = $now;
        $item->updatedAt = $now;
        $this->askCloser->closeAsksHolding($item, $actor, $now);

        return true;
    }

    private static function assertClosed(InboxItemState $state): void
    {
        if (InboxItemState::Open === $state) {
            throw new \LogicException('An item closes into a closed state.');
        }
    }

    /** Why the item takes no response now, or null when it does. */
    private function refusal(InboxItem $item): ?string
    {
        if (InboxItemKind::Review === $item->kind && InboxItemState::Open !== $item->state) {
            return self::ERROR_FINAL;
        }

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
