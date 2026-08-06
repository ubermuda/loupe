<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;

final readonly class ListCommentRepliesView
{
    /** @param list<Comment> $replies */
    public function __construct(
        public array $replies,
    ) {
    }
}
