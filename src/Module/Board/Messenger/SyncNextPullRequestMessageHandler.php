<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\SyncNextPullRequestCommand;
use App\Module\Board\Command\SyncNextPullRequestHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncNextPullRequestMessageHandler
{
    public function __construct(
        private SyncNextPullRequestHandler $syncNextPullRequest,
    ) {
    }

    public function __invoke(SyncNextPullRequest $message): void
    {
        ($this->syncNextPullRequest)(new SyncNextPullRequestCommand($message->projectId, $message->retryPullRequestId, $message->retrySha, $message->attempt));
    }
}
