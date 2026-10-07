<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A discovery worker ended while its run still waited for a report, so the run failed. */
#[AsEventListener]
final readonly class FailDiscoveryOnRunEnded
{
    public const string WORK_KIND = 'discovery';

    /** The work goes on in another run: a newer event took the place, or the bridge dropped its claim so the request opens again. */
    private const array CONTINUING = [WorkerRunState::Replaced, WorkerRunState::Skipped, WorkerRunState::Dropped];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    public function __invoke(WorkerRunChanged $event): void
    {
        foreach ($event->runIds as $runId) {
            $run = $this->workerRuns->find($runId);
            $cardId = $run?->cardId();
            if (null === $run || null === $cardId || self::WORK_KIND !== $run->workKind
                || $run->state->isOpen() || \in_array($run->state, self::CONTINUING, true)) {
                continue;
            }

            ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($cardId, $run->failureReason ?? $run->state->value));
        }
    }
}
