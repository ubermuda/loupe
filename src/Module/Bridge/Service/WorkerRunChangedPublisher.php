<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open worker run pages of a project to reload. The message carries
 * no run, because what a page shows depends on who is looking at it. It also
 * tells the open boards when a run of a card changes, which can change its warning.
 */
final readonly class WorkerRunChangedPublisher
{
    public const string TYPE = 'worker_run.changed';

    public const string CARD_WARNING_CHANGED = 'worker_run.card_warning_changed';

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

    /** Tells the open boards to place the card again. Call it after the change commits. */
    public function cardWarningChanged(Uuid $projectId, Uuid $cardId): void
    {
        $this->publisher->queue($this->topics->forBoard($projectId), ['type' => self::CARD_WARNING_CHANGED, 'cardId' => (string) $cardId]);
    }
}
