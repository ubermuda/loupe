<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Writes one `run-finished` history row for each run that closed. The event
 * comes after the commit, so this listener flushes its own rows.
 */
#[AsEventListener]
final readonly class WriteCardEventOnRunFinished
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        try {
            $wrote = false;
            foreach ($this->workerRuns->findByIds($event->runIds) as $run) {
                if ($run->state->isOpen()) {
                    continue;
                }
                $card = $this->cards->findOneByIdAndProjectId($run->cardId->toRfc4122(), (string) $run->project->id);
                $runId = (string) $run->id;
                if (null === $card || $this->cardEvents->hasRunFinished($card, $runId)) {
                    continue;
                }

                $this->cardEvents->record(
                    $card,
                    CardEventKind::RunFinished,
                    CardReporter::Agent,
                    $run->project->owner,
                    self::detail($run, $runId),
                    $run->endedAt ?? $this->clock->now(),
                );
                $wrote = true;
            }
            if ($wrote) {
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('board.card_event_write_failed', [
                'projectId' => (string) $event->projectId,
                'runIds' => $event->runIds,
                'exception' => $e,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private static function detail(WorkerRun $run, string $runId): array
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
        ];
    }
}
