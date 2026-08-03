<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class ReviseDocumentCommand
{
    /**
     * @param list<Document>|null $references the document's complete reference
     *                                        set after this revision; null keeps
     *                                        the current one, an empty list clears it
     */
    public function __construct(
        public Document $document,
        public string $markdown,
        public string $description,
        public ?string $title = null,
        public ?array $references = null,
    ) {
    }
}
