<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;

/**
 * Reads a run with every run of its series of resumes. A run can have more
 * than one continuation, so the series is a tree, read oldest report first.
 */
final readonly class ShowWorkerRunSeriesHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerRunStateChangeRepository $workerRunStateChanges,
        private BridgeCommandRepository $bridgeCommands,
    ) {
    }

    public function __invoke(ShowWorkerRunSeriesCommand $command): WorkerRunSeriesView
    {
        $project = $command->run->project;
        $first = $command->run;
        $seen = [(string) $first->id => true];
        while (null !== $first->continuesRun && $first->continuesRun->project === $project && !isset($seen[(string) $first->continuesRun->id])) {
            $first = $first->continuesRun;
            $seen[(string) $first->id] = true;
        }

        $series = [(string) $first->id => $first];
        $frontier = [$first];
        while ([] !== $frontier) {
            $next = [];
            foreach ($this->workerRuns->findContinuationsOf($frontier) as $run) {
                if ($run->project === $project && !isset($series[(string) $run->id])) {
                    $series[(string) $run->id] = $run;
                    $next[] = $run;
                }
            }
            $frontier = $next;
        }
        $series[(string) $command->run->id] = $command->run;

        $runs = array_values($series);
        usort($runs, static fn (WorkerRun $a, WorkerRun $b): int => [$a->receivedAt, (string) $a->id] <=> [$b->receivedAt, (string) $b->id]);

        return new WorkerRunSeriesView(
            run: $command->run,
            runs: $runs,
            stateChanges: $this->workerRunStateChanges->findForRuns($runs),
            commands: $this->bridgeCommands->findForRuns($runs),
        );
    }
}
