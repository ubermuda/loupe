<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Event\BoardColumnsChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tells every open board of a project to reload. The message carries no board
 * content, because what a board shows depends on who is looking at it.
 */
final readonly class PublishBoardRefreshOnBoardColumnsChanged
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    #[AsEventListener]
    public function __invoke(BoardColumnsChanged $event): void
    {
        $projectId = $event->project->id ?? throw new \LogicException('Project has no id.');
        $this->publisher->queue($this->topics->forBoard($projectId), ['type' => 'board.columns_changed']);
    }
}
