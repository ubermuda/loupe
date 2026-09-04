<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;

final readonly class CreateDocumentCommand
{
    /**
     * @param string[]       $tagNames   raw names as typed; normalisation and
     *                                   implicit creation are
     *                                   SetDocumentTagsHandler's job
     * @param list<Document> $references documents the new one points at
     * @param ?string        $seriesName raw name as typed; the series is created
     *                                   if the project does not have it yet
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $markdown,
        public ?string $description = null,
        public array $tagNames = [],
        public array $references = [],
        public ?string $seriesName = null,
        public ?int $seriesOrdinal = null,
    ) {
    }
}
