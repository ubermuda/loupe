<?php

declare(strict_types=1);

namespace App\Module\Bridge\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Whoever may view a project may listen for changes to its worker runs. */
final readonly class WorkerRunTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private ProjectRepository $projects,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $projectId = $this->topics->projectIdFromWorkerRunsTopic($topic);
        if (null === $projectId) {
            return null;
        }

        $project = $this->projects->find($projectId);

        return null !== $project && $this->authorization->isGranted(ProjectVoter::VIEW, $project);
    }
}
