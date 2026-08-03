<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;

final readonly class CreateDocumentCommand
{
    /**
     * @param list<Document> $references documents the new one points at
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $markdown,
        public ?string $description = null,
        public array $references = [],
    ) {
    }
}
