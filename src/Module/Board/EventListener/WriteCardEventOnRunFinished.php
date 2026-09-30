<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Writes one `run-finished` history row for each run that closed. A run that
 * reopens and closes with a different state updates its row. The event
 * comes after a commit, or inside a card move's transaction, so the rows go
 * through DBAL and never flush the entity manager.
 */
#[AsEventListener]
final readonly class WriteCardEventOnRunFinished
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunStateChangeRepository $workerRunStateChanges,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        try {
            $runs = $this->workerRuns->findByIds($event->runIds);
            $changes = $this->workerRunStateChanges->findForRuns($runs);
        } catch (\Throwable $e) {
            $this->logger->warning('board.card_event_write_failed', ['projectId' => (string) $event->projectId, 'exception' => $e]);

            return;
        }

        // One try per run, so a failed row does not cost the other runs theirs.
        foreach ($runs as $run) {
            if ($run->state->isOpen()) {
                continue;
            }

            try {
                $card = $this->cards->findOneByIdAndProjectId($run->cardId->toRfc4122(), (string) $run->project->id);
                if (null !== $card) {
                    $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
                    // A timeout sets no end, so the newest change into the current state dates the close.
                    // A new close then moves the row, and a repeated dispatch does not.
                    $runChanges = $changes[$runId->toRfc4122()] ?? [];
                    $last = self::newest($runChanges);
                    $closedAt = $run->endedAt ?? self::newest($runChanges, $run->state)->at ?? $this->clock->now();
                    $detail = self::detail($run, $runId->toRfc4122(), $closedAt) + ['stateSequence' => null === $last?->sequence ? null : (int) $last->sequence];
                    $this->cardEvents->upsertRunFinished($card, $runId, $run->project->owner, $detail, $closedAt);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('board.card_event_write_failed', [
                    'projectId' => (string) $event->projectId,
                    'runId' => (string) $run->id,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * The change written last, into the given state when one is given. A report
     * can carry an earlier time than a change before it, so time does not tell.
     *
     * @param list<WorkerRunStateChange> $changes
     */
    private static function newest(array $changes, ?WorkerRunState $state = null): ?WorkerRunStateChange
    {
        $newest = null;
        foreach ($changes as $change) {
            if (null !== $state && $change->state !== $state) {
                continue;
            }
            if (null === $newest || (int) $change->sequence > (int) $newest->sequence) {
                $newest = $change;
            }
        }

        return $newest;
    }

    /** @return array<string, mixed> */
    private static function detail(WorkerRun $run, string $runId, \DateTimeImmutable $closedAt): array
    {
        $startedAt = $run->startedAt;
        $endedAt = $run->endedAt;

        return [
            'runId' => $runId,
            'ruleName' => $run->ruleName,
            'state' => $run->state->value,
            'interactive' => WorkerRunKind::Interactive === $run->kind,
            'startedAt' => $startedAt?->format(\DateTimeInterface::ATOM),
            'endedAt' => $endedAt?->format(\DateTimeInterface::ATOM),
            'durationSeconds' => null !== $startedAt && null !== $endedAt ? $endedAt->getTimestamp() - $startedAt->getTimestamp() : null,
            'resumeIndex' => $run->resumeIndex,
            'resumeCap' => $run->resumeCap,
            'closedAt' => $closedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
