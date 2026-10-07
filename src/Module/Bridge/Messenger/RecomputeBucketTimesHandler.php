<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

use App\Module\Bridge\Command\RecomputeProjectBucketTimesCommand;
use App\Module\Bridge\Command\RecomputeProjectBucketTimesHandler;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecomputeBucketTimesHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private RecomputeProjectBucketTimesHandler $recompute,
    ) {
    }

    public function __invoke(RecomputeBucketTimes $message): void
    {
        $project = $this->projects->find($message->projectId);
        if (null === $project) {
            return;
        }

        ($this->recompute)(new RecomputeProjectBucketTimesCommand($project));
    }
}
