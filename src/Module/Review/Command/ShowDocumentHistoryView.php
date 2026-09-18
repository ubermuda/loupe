<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\DocumentVersionHistoryEntry;

final readonly class ShowDocumentHistoryView
{
    /**
     * @param list<DocumentVersionHistoryEntry> $versions newest first
     */
    public function __construct(
        public Document $document,
        public array $versions,
    ) {
    }
}
