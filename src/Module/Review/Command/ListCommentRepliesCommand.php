<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;

final readonly class ListCommentRepliesCommand
{
    public function __construct(
        public Comment $parent,
    ) {
    }
}
