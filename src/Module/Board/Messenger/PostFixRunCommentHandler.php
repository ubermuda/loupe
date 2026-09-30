<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\PostPullRequestCommentCommand;
use App\Module\Board\Command\PostPullRequestCommentHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PostFixRunCommentHandler
{
    public function __construct(
        private PostPullRequestCommentHandler $postPullRequestComment,
    ) {
    }

    public function __invoke(PostFixRunComment $message): void
    {
        ($this->postPullRequestComment)(new PostPullRequestCommentCommand($message->commentId));
    }
}
