<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\DeliverPullRequestNoticeCommand;
use App\Module\Board\Command\DeliverPullRequestNoticeHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PostPullRequestNoticeHandler
{
    public function __construct(
        private DeliverPullRequestNoticeHandler $deliverPullRequestNotice,
    ) {
    }

    public function __invoke(PostPullRequestNotice $message): void
    {
        ($this->deliverPullRequestNotice)(new DeliverPullRequestNoticeCommand($message->noticeId));
    }
}
