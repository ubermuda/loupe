<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;

final readonly class CreateDocumentCommand
{
    /**
     * @param string[] $tagNames raw names as typed; normalisation and implicit
     *                           creation are SetDocumentTagsHandler's job
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $markdown,
        public ?string $description = null,
        public array $tagNames = [],
    ) {
    }
}
