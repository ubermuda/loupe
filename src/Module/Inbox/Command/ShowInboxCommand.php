<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;

final readonly class ShowInboxCommand
{
    public function __construct(
        public Project $project,
        /** The page of closed asks, or of search results, counting from 1. */
        public int $page = 1,
        /** The search as typed. A blank one shows the asks. */
        public string $query = '',
        /** The completed queue, rather than the open one. */
        public bool $completed = false,
    ) {
    }
}
