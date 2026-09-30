<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
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
        private WorkerRunStateChangeRepository $stateChanges,
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
            $changes = $this->stateChanges->findForRuns($runs);
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
                    // A timeout sets no end, so the last state change dates the close.
                    // A new close then moves the row, and a repeated dispatch does not.
                    $last = $changes[$runId->toRfc4122()] ?? [];
                    $closedAt = $run->endedAt ?? ([] === $last ? null : array_last($last)->at) ?? $this->clock->now();
                    $this->cardEvents->upsertRunFinished($card, $runId, $run->project->owner, self::detail($run, $runId->toRfc4122(), $closedAt), $closedAt);
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
