<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\CommentSignals;

/**
 * One row on the documents list: the document plus the derived values shown on
 * the row. That is the current version number, when that version was submitted,
 * and what its comment threads say about it.
 */
final readonly class DocumentListItem
{
    public function __construct(
        public Document $document,
        public int $versionNumber,
        public \DateTimeImmutable $updatedAt,
        public CommentSignals $signals,
    ) {
    }
}
