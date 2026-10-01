<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowExperimentCommand
{
    public function __construct(
        public Project $project,
        public string $experiment,
        /** The page of the Cards tab. */
        public int $page = 1,
        /** Narrows the Cards tab to the kept cards of one variant. */
        public ?string $variant = null,
        /** Narrows the Cards tab to the cards the report leaves out. */
        public bool $leftOutOnly = false,
    ) {
    }
}
