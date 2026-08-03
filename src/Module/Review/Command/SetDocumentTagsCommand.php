<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class SetDocumentTagsCommand
{
    /**
     * @param string[] $tagNames raw names as typed; the handler normalises,
     *                           de-duplicates and creates whatever is missing
     */
    public function __construct(
        public Document $document,
        public array $tagNames,
    ) {
    }
}
