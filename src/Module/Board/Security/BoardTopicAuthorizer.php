<?php

declare(strict_types=1);

namespace App\Module\Board\Security;

use App\Mercure\MercureTopicAuthorizerInterface;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Whoever may view a project may listen for changes to its board. */
final readonly class BoardTopicAuthorizer implements MercureTopicAuthorizerInterface
{
    public function __construct(
        private ProjectTopicBuilder $topics,
        private ProjectRepository $projects,
        private BoardAvailability $board,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    #[\Override]
    public function mayCurrentUserSubscribe(string $topic): ?bool
    {
        $projectId = $this->topics->projectIdFromBoardTopic($topic);
        if (null === $projectId) {
            return null;
        }

        $project = $this->projects->find($projectId);

        return null !== $project
            && $this->board->isEnabled()
            && $this->authorization->isGranted(ProjectVoter::VIEW, $project);
    }
}
