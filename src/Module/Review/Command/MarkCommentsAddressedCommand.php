<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;

final readonly class MarkCommentsAddressedCommand
{
    /**
     * @param list<Comment> $comments the batch, already resolved and authorized by the caller
     */
    public function __construct(
        public array $comments,
    ) {
    }
}
