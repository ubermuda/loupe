<?php

declare(strict_types=1);

namespace App\Module\Review\View;

use App\Module\Review\Entity\Document;

/**
 * One row on the documents list: the document plus the derived values shown on
 * the row — the current version number, when that version was submitted, and how
 * many top-level threads are still open. The counts are gathered in the
 * controller (the only place allowed to read across repositories) so this view
 * model imports only its own module.
 */
final readonly class DocumentListItem
{
    public function __construct(
        public Document $document,
        public int $versionNumber,
        public \DateTimeImmutable $updatedAt,
        public int $openThreadCount,
    ) {
    }
}
