<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\StageCard;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
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
use App\Module\Inbox\Entity\InboxProjectSettings;
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
        private InboxWaitSwitches $switches,
        private WorkerRunRepository $workerRuns,
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private CardAutomationRepository $cardAutomations,
        private BoardAutomation $boardAutomation,
        private StageCard $stageCard,
    ) {
    }

    /** @param list<string>|null $cardIds null for every card that has an open watch, a document in review, a newest run that waits or an open GitHub pull request */
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

            $switches = $this->switches->for($project);
            $wanted = $enabled ? self::switchedOn($this->wantedWaits($project, $cards), $switches) : [];
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

                if ($this->apply($watch, $want, $this->cause($card, $enabled), $switches, $now)) {
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
        $cardIds ??= [
            ...$this->inboxCardWatches->findOpenCardIds($project),
            ...$this->cardDocuments->findCardIdsWithDocumentInReview($project),
            ...array_map(
                static fn (array $row): string => $row['card_id'],
                array_filter($this->workerRuns->findLatestRunRows($project, null), static fn (array $row): bool => null !== self::runTrigger($row['state'])),
            ),
            ...$this->cardPullRequests->findCardIdsWithOpenGitHubPullRequest($project),
        ];

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
     * @return array<string, array<string, WantedCardWait>> card id => wait key => wait
     */
    private function wantedWaits(Project $project, array $cards): array
    {
        $open = array_filter($cards, static fn (Card $card): bool => !$card->column->terminal);
        $cardIds = array_values(array_map(static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A stored card has an id.'), $open));
        $candidates = [...$this->documentWaits($project, $cardIds), ...$this->runWaits($project, $cardIds, $open), ...$this->pullRequestWaits($project, $cardIds)];
        if ([] === $candidates) {
            return [];
        }

        $dismissed = [];
        foreach ($this->inboxCardWatches->findDismissedForCards($project, $cardIds) as $watch) {
            foreach ($watch->waits as $wait) {
                if (InboxCardWaitEndReason::Dismissed === $wait->endReason) {
                    $dismissed[(string) $watch->cardId][$wait->key()] = true;
                }
            }
        }

        $wanted = [];
        foreach ($candidates as [$cardId, $wait]) {
            $key = $wait->key();
            if (!isset($dismissed[$cardId][$key])) {
                $wanted[$cardId][$key] = $wait;
            }
        }

        return $wanted;
    }

    /**
     * @param array<string, array<string, WantedCardWait>> $wanted card id => wait key => wait
     *
     * @return array<string, array<string, WantedCardWait>>
     */
    private static function switchedOn(array $wanted, InboxProjectSettings $switches): array
    {
        foreach ($wanted as $cardId => $waits) {
            $wanted[$cardId] = array_filter($waits, static fn (WantedCardWait $wait): bool => $switches->isOn($wait->trigger));
        }

        return array_filter($wanted);
    }

    /**
     * Only a stage document of the card waits, and only while the card sits in
     * the column the stage starts from. An open review of an agent on the
     * document holds its wait back.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<array{string, WantedCardWait}>
     */
    private function documentWaits(Project $project, array $cardIds): array
    {
        $rows = array_values(array_filter(
            $this->cardDocuments->findInReviewForCards($project, $cardIds),
            fn (array $row): bool => $this->stageCard->forDocument($row['link']->document, [$row['link']]) === $row['link']->card,
        ));
        if ([] === $rows) {
            return [];
        }

        $documents = [];
        foreach ($rows as $row) {
            $documents[(string) $row['link']->document->id] = $row['link']->document;
        }
        $underAgentReview = array_flip($this->inboxReviews->findDocumentIdsUnderAgentReview(array_values($documents)));

        $waits = [];
        foreach ($rows as $row) {
            $document = $row['link']->document;
            if (!isset($underAgentReview[(string) $document->id])) {
                $waits[] = [(string) $row['link']->card->id, WantedCardWait::forDocument($document, $row['versionNumber'])];
            }
        }

        return $waits;
    }

    /**
     * The newest run of a card waits only while the card stays in the column that started it.
     *
     * @param list<Uuid>          $cardIds
     * @param array<string, Card> $cards
     *
     * @return list<array{string, WantedCardWait}>
     */
    private function runWaits(Project $project, array $cardIds, array $cards): array
    {
        $waits = [];
        foreach ($this->workerRuns->findLatestRunRows($project, $cardIds) as $row) {
            $cardId = Uuid::fromString($row['card_id'])->toRfc4122();
            $trigger = self::runTrigger($row['state']);
            if (null === $trigger || null === $row['card_column'] || $row['card_column'] !== ($cards[$cardId] ?? null)?->column->slug) {
                continue;
            }
            $waits[] = [$cardId, WantedCardWait::forRun($trigger, Uuid::fromString($row['id']), $row['output'])];
        }

        return $waits;
    }

    /**
     * An open run on the card holds back a ready wait, and a fix-stopped wait
     * with no blocked reason, because the run can still push a fix.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<array{string, WantedCardWait}>
     */
    private function pullRequestWaits(Project $project, array $cardIds): array
    {
        $links = $this->cardPullRequests->findGitHubReferencesForCards($cardIds);
        if ([] === $links) {
            return [];
        }

        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $rows = [];
        foreach ($this->forgePullRequests->findByKeys($projectId, array_map(static fn (array $link): array => ['forge' => Forge::GitHub->value, 'repository' => $link['repository'], 'number' => $link['number']], $links)) as $row) {
            $rows[$row->repository.'#'.$row->number] = $row;
        }
        if ([] === $rows) {
            return [];
        }

        $automations = $this->cardAutomations->findByCardIds($cardIds);
        $loopLimit = $this->boardAutomation->settingsOf($project)->loopLimit;
        $running = array_flip($this->workerRuns->findCardIdsWithOpenRun($project, $cardIds));

        $waits = [];
        foreach ($links as $link) {
            $row = $rows[mb_strtolower($link['repository']).'#'.$link['number']] ?? null;
            if (null === $row || null === $row->refreshedAt || PullRequestState::Open !== $row->state || null === $row->headSha) {
                continue;
            }
            $cardId = $link['cardId'];
            $rowId = $row->id ?? throw new \LogicException('A stored pull request has an id.');
            $idle = !isset($running[$cardId]);
            $automation = $automations[$cardId] ?? null;

            if ($idle && self::waitsForReview($row, $row->headSha)) {
                $waits[] = [$cardId, WantedCardWait::forPullRequestReady($rowId, $row->number, $row->headSha)];
            }
            if (null !== $automation && (null !== $automation->blockedReason || ($idle && $automation->fixRounds >= $loopLimit))) {
                $waits[] = [$cardId, WantedCardWait::forPullRequestFixStopped($rowId, $row->number, $row->headSha, $automation->blockedReason)];
            }
        }

        return $waits;
    }

    /** A changes-requested review on an older commit waits again, because GitHub keeps it until someone reviews anew. */
    private static function waitsForReview(ForgePullRequest $row, string $headSha): bool
    {
        $review = match ($row->review) {
            PullRequestReview::Required, PullRequestReview::None => true,
            PullRequestReview::ChangesRequested => null !== $row->changesRequestedSha && $row->changesRequestedSha !== $headSha,
            PullRequestReview::Approved => false,
        };

        return $review
            && !$row->draft
            && PullRequestChecks::Passed === $row->checks
            && $row->checksSha === $headSha
            && \in_array($row->mergeability, [PullRequestMergeability::Mergeable, PullRequestMergeability::Behind, PullRequestMergeability::Blocked], true);
    }

    private static function runTrigger(string $state): ?InboxCardWaitTrigger
    {
        return match (WorkerRunState::tryFrom($state)) {
            WorkerRunState::Blocked => InboxCardWaitTrigger::RunBlocked,
            WorkerRunState::GaveUp => InboxCardWaitTrigger::RunGaveUp,
            WorkerRunState::WaitingForPerson => InboxCardWaitTrigger::RunWaitingForPerson,
            default => null,
        };
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
     * @param array<string, WantedCardWait> $want
     */
    private function apply(InboxCardWatch $watch, array $want, InboxCardWaitEndReason $cause, InboxProjectSettings $switches, \DateTimeImmutable $now): bool
    {
        $open = [];
        foreach ($watch->waits as $wait) {
            if (null === $wait->endedAt) {
                $open[$wait->key()] = $wait;
            }
        }

        $obsolete = InboxCardWaitEndReason::Resolved !== $cause;
        foreach ($open as $key => $wait) {
            if (!isset($want[$key])) {
                $wait->endedAt = $now;
                $wait->endReason = InboxCardWaitEndReason::Resolved === $cause && !$switches->isOn($wait->trigger) ? InboxCardWaitEndReason::SwitchedOff : $cause;
                $obsolete = $obsolete || InboxCardWaitEndReason::Resolved !== $wait->endReason;
                unset($open[$key]);
            }
        }

        foreach ($want as $key => $wanted) {
            if (isset($open[$key])) {
                continue;
            }
            $open[$key] = new InboxCardWait(
                watch: $watch,
                trigger: $wanted->trigger,
                reason: $wanted->reason,
                documentId: $wanted->document?->id,
                versionNumber: $wanted->versionNumber,
                runId: $wanted->runId,
                pullRequestId: $wanted->pullRequestId,
                headSha: $wanted->headSha,
                startedAt: $now,
            );
            $watch->waits->add($open[$key]);
            if (null !== $wanted->document) {
                $this->link($watch->item, $wanted->document, $now);
            }
        }

        if ([] !== $open) {
            return false;
        }

        $state = $obsolete ? InboxItemState::Obsolete : InboxItemState::Done;
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
