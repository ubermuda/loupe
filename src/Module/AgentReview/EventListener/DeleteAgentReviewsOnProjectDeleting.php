<?php

declare(strict_types=1);

namespace App\Module\AgentReview\EventListener;

use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Project\Event\ProjectDeleting;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Bulk-deletes the agent reviews of a project, inside ProjectDeleter's transaction. */
#[AsEventListener]
final readonly class DeleteAgentReviewsOnProjectDeleting
{
    public function __construct(
        private AgentReviewRepository $agentReviews,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->agentReviews->deleteByProject($event->project);
    }
}
