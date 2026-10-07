<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\OpenCardRun;
use App\Module\Project\Entity\Project;

/** The cards of a project that a worker or a session works on now, one run per card. */
final readonly class OpenCardRuns
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    /**
     * The most advanced run comes first, and on a tie the run that has waited
     * or run the longest. A card keeps its first run in that order.
     *
     * @return list<OpenCardRun>
     */
    public function forProject(Project $project): array
    {
        $kept = [];
        foreach ($this->workerRuns->findOpenOfProject($project) as $run) {
            if (!$run->subject()->isCard()) {
                continue;
            }
            $candidate = self::view($run);
            $cardId = (string) $candidate->cardId;
            if (!isset($kept[$cardId]) || self::compare($candidate, $kept[$cardId]) < 0) {
                $kept[$cardId] = $candidate;
            }
        }

        $runs = array_values($kept);
        usort($runs, self::compare(...));

        return $runs;
    }

    private static function view(WorkerRun $run): OpenCardRun
    {
        $since = \in_array($run->state, [WorkerRunState::Preparing, WorkerRunState::Running, WorkerRunState::Stopping], true) ? $run->startedAt ?? $run->receivedAt : $run->receivedAt;

        return new OpenCardRun($run->subjectId, $run->state, $run->kind, $run->workKind, $since);
    }

    private static function compare(OpenCardRun $left, OpenCardRun $right): int
    {
        return [$right->state->rank(), $left->since] <=> [$left->state->rank(), $right->since];
    }
}
