<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\CloseBackloggedEpicPullRequestsCommand;
use App\Module\Board\Command\CloseBackloggedEpicPullRequestsHandler;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CloseEpicPullRequestsHandler
{
    public function __construct(
        private CloseBackloggedEpicPullRequestsHandler $closePullRequests,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(CloseEpicPullRequests $message): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->closePullRequests)(new CloseBackloggedEpicPullRequestsCommand($message->cardId));
    }
}
