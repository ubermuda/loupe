<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class ShowDocumentHistoryView
{
    /**
     * @param list<array{versionNumber: int, createdAt: \DateTimeImmutable, description: ?string}> $versions newest first
     */
    public function __construct(
        public Document $document,
        public array $versions,
    ) {
    }
}
