<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Experiment\ExperimentSummary;
use App\Module\Bridge\Repository\ExperimentPinRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;

final readonly class ListExperimentsHandler
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
        private ExperimentPinRepository $experimentPins,
    ) {
    }

    public function __invoke(ListExperimentsCommand $command): ListExperimentsView
    {
        /** @var array<string, array<string, true>> $cards experiment => card id set */
        $cards = [];
        /** @var array<string, \DateTimeImmutable> $lastRunAt */
        $lastRunAt = [];
        foreach ($this->workerRuns->findExperimentCardRows($command->project) as $row) {
            $cards[$row['experiment']][$row['cardId']] = true;
            if (!isset($lastRunAt[$row['experiment']]) || $row['lastRunAt'] > $lastRunAt[$row['experiment']]) {
                $lastRunAt[$row['experiment']] = $row['lastRunAt'];
            }
        }
        foreach ($this->experimentPins->findExperimentCardRows($command->project) as $row) {
            $cards[$row['experiment']][$row['cardId']] = true;
        }

        $experiments = [];
        foreach ($cards as $name => $ids) {
            $experiments[] = new ExperimentSummary((string) $name, \count($ids), $lastRunAt[$name] ?? null);
        }
        usort($experiments, static fn (ExperimentSummary $a, ExperimentSummary $b): int => [null === $a->lastRunAt, $b->lastRunAt, $a->name] <=> [null === $b->lastRunAt, $a->lastRunAt, $b->name]);

        return new ListExperimentsView($experiments);
    }
}
