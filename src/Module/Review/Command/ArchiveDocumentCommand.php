<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class ArchiveDocumentCommand
{
    public function __construct(
        public Document $document,
        /**
         * Why the document is being archived. Optional here because the app's
         * archive button has no field for it; the MCP tool is what makes a
         * caller supply one.
         */
        public ?string $reason = null,
    ) {
    }
}
