<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\InboxEventType;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Repository\InboxReviewRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Computes the waits each card has now, compares them with the open wait item
 * of the card, and writes the difference. Running it twice changes nothing, so
 * any trigger may ask for it again.
 */
final readonly class CardWaitReconciler
{
    public function __construct(
        private EntityManagerInterface $em,
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
        private InboxCardWatchRepository $inboxCardWatches,
        private InboxReviewRepository $inboxReviews,
        private InboxItemRepository $inboxItems,
        private InboxItemCloser $closer,
        private InboxSearchIndexer $searchIndexer,
        private InboxOpenCountPublisher $openCount,
        private InboxAvailability $inbox,
    ) {
    }

    /** @param list<string>|null $cardIds null for every card that has an open watch or a document in review */
    public function reconcile(Project $project, ?array $cardIds): void
    {
        $countChanged = $this->em->wrapInTransaction(function () use ($project, $cardIds): bool {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $ids = $this->cardIds($project, $cardIds);
            if ([] === $ids) {
                return false;
            }

            $now = self::now();
            $enabled = $this->inbox->isEnabled();
            $cards = [];
            foreach ($this->cards->findByIdsInProject($project, array_values($ids)) as $card) {
                $cards[(string) $card->id] = $card;
            }
            $watches = [];
            foreach ($this->inboxCardWatches->findOpenForCards($project, array_values($ids)) as $watch) {
                $watches[(string) $watch->cardId] = $watch;
            }

            // Doctrine inserts before it updates, so a watch must be closed in
            // the database before a new one of the same card can be inserted.
            $stale = false;
            foreach ($watches as $cardId => $watch) {
                if (InboxItemState::Open !== $watch->item->state) {
                    $reason = null === $watch->dismissedAt ? $this->cause($cards[$cardId] ?? null, $enabled) : InboxCardWaitEndReason::Dismissed;
                    $this->closeWatch($watch, $reason, $now);
                    unset($watches[$cardId]);
                    $stale = true;
                }
            }
            if ($stale) {
                $this->em->flush();
            }

            $wanted = $enabled ? $this->wantedWaits($project, $cards) : [];
            $countChanged = false;
            $nextNumber = null;
            $rewritten = [];
            foreach (array_keys($ids) as $cardId) {
                $card = $cards[$cardId] ?? null;
                $want = $wanted[$cardId] ?? [];
                $watch = $watches[$cardId] ?? null;
                if (null === $watch) {
                    if (null === $card || [] === $want) {
                        continue;
                    }
                    $nextNumber ??= $this->inboxItems->nextNumber($project);
                    $watch = $this->open($project, $card, $nextNumber++, $now);
                    $countChanged = true;
                }

                if ($this->apply($watch, $want, $this->cause($card, $enabled), $now)) {
                    $countChanged = true;
                } elseif (null !== $card && $this->rewrite($watch, $card, $now)) {
                    $rewritten[] = $watch->item;
                }
            }

            $this->em->flush();
            foreach ($rewritten as $item) {
                $this->searchIndexer->index($item);
            }

            return $countChanged;
        });

        if ($countChanged) {
            $this->openCount->countChanged($project);
        }
    }

    /**
     * @param list<string>|null $cardIds
     *
     * @return array<string, Uuid> card id => the same id as a Uuid
     */
    private function cardIds(Project $project, ?array $cardIds): array
    {
        $cardIds ??= [...$this->inboxCardWatches->findOpenCardIds($project), ...$this->cardDocuments->findCardIdsWithDocumentInReview($project)];

        $ids = [];
        foreach ($cardIds as $cardId) {
            $uuid = Uuid::fromString($cardId);
            $ids[$uuid->toRfc4122()] = $uuid;
        }

        return $ids;
    }

    /**
     * @param array<string, Card> $cards
     *
     * @return array<string, array<string, array{document: Document, versionNumber: int}>> card id => wait key => wait
     */
    private function wantedWaits(Project $project, array $cards): array
    {
        $open = array_filter($cards, static fn (Card $card): bool => !$card->column->terminal);
        $cardIds = array_values(array_map(static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A stored card has an id.'), $open));
        $rows = $this->cardDocuments->findInReviewForCards($project, $cardIds);
        if ([] === $rows) {
            return [];
        }

        $documents = [];
        foreach ($rows as $row) {
            $documents[(string) $row['link']->document->id] = $row['link']->document;
        }
        $underAgentReview = array_flip($this->inboxReviews->findDocumentIdsUnderAgentReview(array_values($documents)));
        $dismissed = [];
        foreach ($this->inboxCardWatches->findDismissedForCards($project, $cardIds) as $watch) {
            foreach ($watch->waits as $wait) {
                if (InboxCardWaitEndReason::Dismissed === $wait->endReason) {
                    $dismissed[(string) $watch->cardId][$wait->key()] = true;
                }
            }
        }

        $wanted = [];
        foreach ($rows as $row) {
            $cardId = (string) $row['link']->card->id;
            $document = $row['link']->document;
            $documentId = $document->id ?? throw new \LogicException('A stored document has an id.');
            $key = InboxCardWait::computeKey(InboxCardWaitTrigger::DocumentInReview, $documentId, $row['versionNumber']);
            if (isset($underAgentReview[(string) $documentId]) || isset($dismissed[$cardId][$key])) {
                continue;
            }
            $wanted[$cardId][$key] = ['document' => $document, 'versionNumber' => $row['versionNumber']];
        }

        return $wanted;
    }

    /** Why a wait of this card that is no longer wanted ends. */
    private function cause(?Card $card, bool $enabled): InboxCardWaitEndReason
    {
        return match (true) {
            null === $card => InboxCardWaitEndReason::CardDeleted,
            $card->column->terminal => InboxCardWaitEndReason::CardFinished,
            !$enabled => InboxCardWaitEndReason::SwitchedOff,
            default => InboxCardWaitEndReason::Resolved,
        };
    }

    private function open(Project $project, Card $card, int $number, \DateTimeImmutable $now): InboxCardWatch
    {
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $title = self::title($card);
        $item = new InboxItem(
            project: $project,
            number: $number,
            kind: InboxItemKind::Wait,
            title: $title,
            blocking: true,
            createdAt: $now,
            searchLanguage: $project->searchLanguage,
        );
        $item->cards->add(new InboxItemCard($item, $card, $now));
        $this->em->persist($item);

        $ask = new InboxAsk(project: $project, sessionId: null, bridgeId: null, createdAt: $now, origin: InboxAskOrigin::Loupe);
        $ask->items->add(new InboxAskItem($ask, $item, $now));
        $this->em->persist($ask);

        $watch = new InboxCardWatch($item, $cardId, $card->number, $now);
        $this->em->persist($watch);

        return $watch;
    }

    /**
     * Ends the waits no longer wanted and starts the new ones. It closes the
     * item when no wait is left, and returns whether it did.
     *
     * @param array<string, array{document: Document, versionNumber: int}> $want
     */
    private function apply(InboxCardWatch $watch, array $want, InboxCardWaitEndReason $cause, \DateTimeImmutable $now): bool
    {
        $open = [];
        foreach ($watch->waits as $wait) {
            if (null === $wait->endedAt) {
                $open[$wait->key()] = $wait;
            }
        }

        foreach ($open as $key => $wait) {
            if (!isset($want[$key])) {
                $wait->endedAt = $now;
                $wait->endReason = $cause;
                unset($open[$key]);
            }
        }

        foreach ($want as $key => $wanted) {
            if (isset($open[$key])) {
                continue;
            }
            $document = $wanted['document'];
            $reason = mb_substr(\sprintf('%s in review, version %d', $document->title, $wanted['versionNumber']), 0, InboxCardWait::MAX_REASON_LENGTH);
            $open[$key] = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, $reason, $document->id, $wanted['versionNumber'], startedAt: $now);
            $watch->waits->add($open[$key]);
            $this->link($watch->item, $document, $now);
        }

        if ([] !== $open) {
            return false;
        }

        $state = InboxCardWaitEndReason::Resolved === $cause ? InboxItemState::Done : InboxItemState::Obsolete;
        $this->closer->close($watch->item, $state, null, $now, InboxEventType::ACTOR_AGENT);
        $watch->closedAt = $now;

        return true;
    }

    /** Ends the open waits of a watch whose item is already closed. */
    private function closeWatch(InboxCardWatch $watch, InboxCardWaitEndReason $reason, \DateTimeImmutable $now): void
    {
        foreach ($watch->waits as $wait) {
            if (null === $wait->endedAt) {
                $wait->endedAt = $now;
                $wait->endReason = $reason;
            }
        }
        $watch->closedAt = $now;
    }

    private function link(InboxItem $item, Document $document, \DateTimeImmutable $now): void
    {
        foreach ($item->documents as $link) {
            if ($link->document === $document) {
                return;
            }
        }
        $item->documents->add(new InboxItemDocument($item, $document, $now));
    }

    /** Writes the title and the body from the card and the open waits, and returns whether either changed. */
    private function rewrite(InboxCardWatch $watch, Card $card, \DateTimeImmutable $now): bool
    {
        $open = array_filter($watch->waits->toArray(), static fn (InboxCardWait $wait): bool => null === $wait->endedAt);
        usort($open, static fn (InboxCardWait $a, InboxCardWait $b): int => [$a->startedAt, $a->key()] <=> [$b->startedAt, $b->key()]);
        $title = self::title($card);
        $body = implode("\n", array_map(static fn (InboxCardWait $wait): string => $wait->reason, $open));

        $item = $watch->item;
        if ($item->title === $title && $item->body === $body) {
            return false;
        }
        $item->title = $title;
        $item->body = $body;
        $item->updatedAt = $now;

        return true;
    }

    private static function title(Card $card): string
    {
        return mb_substr(\sprintf('#%d %s', $card->number, $card->title), 0, InboxItem::MAX_TITLE_LENGTH);
    }

    /** Whole seconds, like the stored columns, so the wait order reads the same before and after a reload. */
    private static function now(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'));
    }
}
