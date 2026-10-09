<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\DocumentFacts;

/** The unarchived documents linked to the parent of the card, empty with no parent. */
final readonly class ParentDocumentsFacts
{
    /** @param list<DocumentFacts> $documents */
    public function __construct(
        public array $documents,
    ) {
    }
}
