<?php

declare(strict_types=1);

namespace App\Outbox\Command;

use App\Module\Project\Entity\Project;
use App\Outbox\View\ActivityListQuery;

final readonly class ListActivityPageCommand
{
    public function __construct(
        public Project $project,
        public ActivityListQuery $listQuery,
        public int $perPage = ListActivityPageHandler::PER_PAGE,
    ) {
    }
}
