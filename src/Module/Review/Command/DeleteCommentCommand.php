<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;

final readonly class DeleteCommentCommand
{
    public function __construct(
        public Comment $comment,
    ) {
    }
}
