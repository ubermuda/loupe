<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Project\Entity\Project;
use App\Module\Review\View\DocumentListQuery;

final readonly class ListDocumentsCommand
{
    public function __construct(
        public Project $project,
        public DocumentListQuery $listQuery,
    ) {
    }
}
