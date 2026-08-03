<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class SetDocumentReferencesCommand
{
    /**
     * @param list<Document> $references the complete set of documents the source
     *                                   should point at; an empty list clears them
     */
    public function __construct(
        public Document $document,
        public array $references,
    ) {
    }
}
