<?php

declare(strict_types=1);

namespace App\Module\Project\View;

use App\Module\Project\Entity\Project;

/**
 * One row on the projects index: the project plus its cross-module rollup
 * counts (documents, submitted reviews, open review comments). The counts are
 * gathered in the controller — the only place allowed to read across module
 * boundaries — so this view model never imports another module.
 */
final readonly class ProjectListItem
{
    public function __construct(
        public Project $project,
        public int $documentCount,
        public int $reviewCount,
        public int $openCount,
    ) {
    }
}
