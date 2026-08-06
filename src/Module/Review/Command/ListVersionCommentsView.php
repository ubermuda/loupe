<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;

final readonly class ListVersionCommentsView
{
    /** @param list<Comment> $comments */
    public function __construct(
        public array $comments,
    ) {
    }
}
