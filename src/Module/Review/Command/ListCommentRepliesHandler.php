<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;

final readonly class ListCommentRepliesHandler
{
    public function __construct(
        private CommentRepository $comments,
    ) {
    }

    public function __invoke(ListCommentRepliesCommand $command): ListCommentRepliesView
    {
        return new ListCommentRepliesView(
            replies: $this->comments->findReplies($command->parent),
        );
    }
}
