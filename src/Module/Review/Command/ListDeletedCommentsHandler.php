<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;

final readonly class ListDeletedCommentsHandler
{
    public function __construct(
        private CommentRepository $comments,
    ) {
    }

    public function __invoke(ListDeletedCommentsCommand $command): ListDeletedCommentsView
    {
        return new ListDeletedCommentsView($command->document, $this->comments->findDeletedByDocument($command->document));
    }
}
