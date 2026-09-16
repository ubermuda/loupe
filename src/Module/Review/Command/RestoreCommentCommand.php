<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;

final readonly class RestoreCommentCommand
{
    public function __construct(
        public Comment $comment,
        public int $deletionSequence,
    ) {
    }
}
