<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class ShowPersistedDecisionBlockCommand
{
    public function __construct(
        public Document $document,
        public ?string $decisionId,
        public ?int $versionNumber,
    ) {
    }
}
