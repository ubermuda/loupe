<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class ReviseDocumentCommand
{
    public function __construct(
        public Uuid $documentId,
        public Project $project,
        public string $markdown,
    ) {
    }
}
