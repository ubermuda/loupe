<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Messenger\ResumeAskingSession;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Outbox\OutboxWriter;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Closes each open ask that a closed item leaves with no open blocking item,
 * writes one inbox.ask_closed row for an ask a bridge can resume, and queues
 * the resume of its session.
 *
 * InboxItemCloser calls it, so the caller holds the project lock and the
 * transaction. It does not flush and throws no domain error, because it runs
 * inside a card move or a column delete.
 */
final readonly class InboxAskCloser
{
    public function __construct(
        private InboxAskRepository $inboxAsks,
        private InboxItemRepository $inboxItems,
        private WorkerRunRepository $workerRuns,
        private CardRepository $cards,
        private OutboxWriter $outbox,
        private MessageBusInterface $bus,
    ) {
    }

    /** @param InboxEventType::ACTOR_* $actor */
    public function closeAsksHolding(InboxItem $item, string $actor, \DateTimeImmutable $now): void
    {
        // Only a blocking item holds an ask back, so no other close can end one.
        if (!$item->blocking) {
            return;
        }

        foreach ($this->inboxAsks->findOpenHolding($item) as $ask) {
            // Closed earlier in this unit of work and not flushed yet.
            if (null !== $ask->closedAt || $this->holdsOpenBlockingItem($ask)) {
                continue;
            }

            $ask->closedAt = $now;
            if (null !== $ask->bridgeId) {
                $ask->card = $this->cardOfSession($ask);
                $this->writeEvent($ask, $actor);
                $this->resumeSession($ask, $now);
            }
        }
    }

    /**
     * Open as stored and open as loaded: a batch closes several items before
     * the flush, and the rows of the items it already closed still read open.
     */
    private function holdsOpenBlockingItem(InboxAsk $ask): bool
    {
        return array_any($this->inboxItems->findOpenBlockingIdsOf($ask), fn ($id) => InboxItemState::Open === $this->inboxItems->find($id)?->state);
    }

    private function cardOfSession(InboxAsk $ask): ?Card
    {
        if (null === $ask->sessionId) {
            return null;
        }
        $run = $this->workerRuns->findFirstOfSession($ask->project, $ask->sessionId);

        return null === $run ? null : $this->cards->findOneByIdAndProjectId((string) $run->cardId, (string) $ask->project->id);
    }

    /** The Doctrine transport commits the message with the close, and the queue sends the resume after it. */
    private function resumeSession(InboxAsk $ask, \DateTimeImmutable $closedAt): void
    {
        if (null === $ask->sessionId || null === $ask->bridgeId) {
            return;
        }

        $this->bus->dispatch(new ResumeAskingSession((string) $ask->project->id, (string) $ask->bridgeId, (string) $ask->sessionId, $closedAt));
    }

    /** @param InboxEventType::ACTOR_* $actor */
    private function writeEvent(InboxAsk $ask, string $actor): void
    {
        // Every key is a contract with the bridge. Ids only: text a person or an
        // agent wrote must never reach an agent through a directive.
        $this->outbox->write($ask->project, InboxEventType::ASK_CLOSED, [
            'type' => InboxEventType::ASK_CLOSED,
            'projectId' => (string) $ask->project->id,
            'subject' => ['type' => 'inbox-ask', 'id' => (string) $ask->id],
            'sessionId' => (string) $ask->sessionId,
            'bridgeId' => (string) $ask->bridgeId,
            'cardId' => null === $ask->card ? null : (string) $ask->card->id,
            'cardNumber' => $ask->card?->number,
            'actor' => $actor,
        ]);
    }
}
