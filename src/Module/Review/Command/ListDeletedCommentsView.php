<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;

final readonly class ListDeletedCommentsView
{
    /** @param list<Comment> $comments */
    public function __construct(
        public Document $document,
        public array $comments,
    ) {
    }
}
