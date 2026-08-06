<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\DocumentDiff;

final readonly class DiffDocumentVersionsView
{
    /**
     * @param list<Comment>                                                                        $comments
     * @param list<array{versionNumber: int, createdAt: \DateTimeImmutable, description: ?string}> $versions
     */
    public function __construct(
        public DocumentVersion $version,
        public ?DocumentDiff $diff,
        public ?DiffRefusal $diffRefusal,
        public array $comments,
        public array $versions,
        public int $orphanedCount,
    ) {
    }
}
