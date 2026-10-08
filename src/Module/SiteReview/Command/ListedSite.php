<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

/** One project of the caller, with what the bridge collects from its tool calls. */
final readonly class ListedSite
{
    /** @param list<string> $subcommandPrograms */
    public function __construct(
        public Project $project,
        public bool $collectFullText,
        public array $subcommandPrograms,
    ) {
    }
}
