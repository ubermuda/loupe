<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Project\Entity\Project;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Announces changed work requests: one event per request, and one reload of the worker run pages per project. */
final readonly class WorkRequestAnnouncer
{
    public function __construct(
        private EventDispatcherInterface $events,
        private WorkerRunChangedPublisher $runsChanged,
    ) {
    }

    /** Call it after the change commits, so a rollback announces nothing. */
    public function announce(WorkRequest ...$requests): void
    {
        /** @var array<string, Project> $projects */
        $projects = [];
        foreach ($requests as $request) {
            $projectId = $request->project->id ?? throw new \LogicException('A persisted project has an id.');
            $projects[$projectId->toRfc4122()] = $request->project;
            $this->events->dispatch(new WorkRequestChanged(
                $projectId,
                $request->subjectType,
                $request->subjectId,
                $request->id ?? throw new \LogicException('A persisted work request has an id.'),
                $request->state,
            ));
        }
        foreach ($projects as $project) {
            $this->runsChanged->runsChanged($project);
        }
    }
}
