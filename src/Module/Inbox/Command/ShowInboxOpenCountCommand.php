<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowInboxOpenCountCommand
{
    public function __construct(
        public Project $project,
    ) {
    }
}
