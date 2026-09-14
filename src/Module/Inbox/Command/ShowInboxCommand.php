<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowInboxCommand
{
    public function __construct(
        public Project $project,
        /** The page of closed asks, counting from 1. */
        public int $page = 1,
    ) {
    }
}
