<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\UserTopicBuilder;
use App\Module\Project\Entity\Project;

/**
 * Tells the owner's open pages that the open count of a project changed, so the
 * sidebar pill reloads it. The message holds no count: two publishes can arrive
 * out of order, and a page that reads the count itself cannot go back in time.
 */
final readonly class InboxOpenCountPublisher
{
    public const string TYPE = 'inbox.open_count_changed';

    public function __construct(
        private UserTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /**
     * An item of the project opened or closed. The topic and payload are built
     * now: after a rollback the EntityManager is closed and cannot load the owner.
     */
    public function countChanged(Project $project): void
    {
        $projectId = $project->id ?? throw new \LogicException('Project has no id.');
        $ownerId = $project->owner->id ?? throw new \LogicException('Project owner has no id.');
        $this->publisher->queue($this->topics->forInbox($ownerId), ['type' => self::TYPE, 'projectId' => (string) $projectId]);
    }
}
