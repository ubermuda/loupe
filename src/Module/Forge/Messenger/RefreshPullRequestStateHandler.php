<?php

declare(strict_types=1);

namespace App\Module\Forge\Messenger;

use App\Module\Forge\Command\ReadPullRequestStateCommand;
use App\Module\Forge\Command\ReadPullRequestStateHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshPullRequestStateHandler
{
    public function __construct(
        private ReadPullRequestStateHandler $readPullRequestState,
    ) {
    }

    public function __invoke(RefreshPullRequestState $message): void
    {
        ($this->readPullRequestState)(new ReadPullRequestStateCommand($message->pullRequestId, $message->requestedAt, $message->verdict, $message->reviewId));
    }
}
