<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Bridge\Entity\WorkerRun;
use Symfony\Component\Uid\Uuid;

/**
 * These runs changed, and with them the runs of these cards. A run whose
 * subject is no card adds no card id. Dispatched after the commit, with ids only.
 */
final readonly class WorkerRunChanged
{
    /**
     * @param list<string> $cardIds RFC 4122 strings
     * @param list<string> $runIds  RFC 4122 strings
     */
    public function __construct(
        public Uuid $projectId,
        public array $cardIds,
        public array $runIds,
    ) {
    }

    /**
     * One event per project, each with its runs and the distinct cards they are about.
     *
     * @param iterable<WorkerRun> $runs
     *
     * @return list<self>
     */
    public static function ofRuns(iterable $runs): array
    {
        $projects = [];
        $cards = [];
        $runIds = [];
        foreach ($runs as $run) {
            $projectId = $run->project->id ?? throw new \LogicException('A persisted project has an id.');
            $key = $projectId->toRfc4122();
            $projects[$key] = $projectId;
            $cards[$key] ??= [];
            $cardId = $run->cardId();
            if (null !== $cardId) {
                $cards[$key][$cardId->toRfc4122()] = true;
            }
            $runIds[$key][($run->id ?? throw new \LogicException('A persisted run has an id.'))->toRfc4122()] = true;
        }

        return array_values(array_map(
            static fn (string $key): self => new self(
                $projects[$key],
                array_map(strval(...), array_keys($cards[$key])),
                array_map(strval(...), array_keys($runIds[$key])),
            ),
            array_keys($projects),
        ));
    }
}
