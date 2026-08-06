<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewEventRepository;

final readonly class ListProjectOutboxHandler
{
    public function __construct(
        private SiteReviewEventRepository $siteReviewEvents,
    ) {
    }

    public function __invoke(ListProjectOutboxCommand $command): ListProjectOutboxView
    {
        return new ListProjectOutboxView(
            project: $command->project,
            events: $this->siteReviewEvents->findUnsentForProject($command->project),
        );
    }
}
