<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;

/**
 * Tells the open worker run pages of a project to reload. The message carries
 * no run, because what a page shows depends on who is looking at it.
 */
final readonly class WorkerRunChangedPublisher
{
    public const string TYPE = 'worker_run.changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /** Call it after the change commits, so a rollback signals nothing. */
    public function runsChanged(Project $project): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $this->publisher->queue($this->topics->forWorkerRuns($projectId), ['type' => self::TYPE]);
    }
}
