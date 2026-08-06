<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;

final readonly class ListVersionCommentsHandler
{
    public function __construct(
        private CommentRepository $comments,
    ) {
    }

    public function __invoke(ListVersionCommentsCommand $command): ListVersionCommentsView
    {
        return new ListVersionCommentsView(
            comments: $this->comments->findByVersion($command->version),
        );
    }
}
