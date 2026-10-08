<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

final readonly class SearchCardsCommand
{
    /** @param list<string> $types the types to list, or every type when empty */
    public function __construct(
        public Project $project,
        public string $query,
        public int $limit,
        public array $types = [],
    ) {
    }
}
