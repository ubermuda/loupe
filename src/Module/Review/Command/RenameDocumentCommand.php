<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class RenameDocumentCommand
{
    public function __construct(
        public Document $document,
        /** Raw as typed or sent; the handler trims it and rejects blank or over-long. */
        public string $title,
    ) {
    }
}
