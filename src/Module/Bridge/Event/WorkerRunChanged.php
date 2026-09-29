<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Bridge\Entity\WorkerRun;
use Symfony\Component\Uid\Uuid;

/**
 * The runs of these cards changed. Dispatched after the commit, with ids only.
 */
final readonly class WorkerRunChanged
{
    /**
     * @param list<string> $cardIds RFC 4122 strings
     */
    public function __construct(
        public Uuid $projectId,
        public array $cardIds,
    ) {
    }

    /**
     * One event per project, each with the distinct cards of its runs.
     *
     * @param iterable<WorkerRun> $runs
     *
     * @return list<self>
     */
    public static function ofRuns(iterable $runs): array
    {
        $projects = [];
        $cards = [];
        foreach ($runs as $run) {
            $projectId = $run->project->id ?? throw new \LogicException('A persisted project has an id.');
            $key = $projectId->toRfc4122();
            $projects[$key] = $projectId;
            $cards[$key][$run->cardId->toRfc4122()] = true;
        }

        return array_values(array_map(
            static fn (string $key): self => new self($projects[$key], array_map(strval(...), array_keys($cards[$key]))),
            array_keys($projects),
        ));
    }
}
