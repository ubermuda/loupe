<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;

/**
 * Tells the open Events pages of a project to reload. The message carries no
 * event, because what a page shows depends on its filters.
 */
final readonly class ActivityChangedPublisher
{
    public const string TYPE = 'activity.changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    public function activityChanged(Project $project): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $this->publisher->queue($this->topics->forActivity($projectId), ['type' => self::TYPE]);
    }
}
