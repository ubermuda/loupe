<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes every project the user owns via ProjectDeleter, which already
 * handles the full project subtree (documents, versions, comments, reviews,
 * site reviews, and the project's own two bound API tokens) inside its own
 * nested transaction.
 *
 * This is the only ORM-based purger, and ProjectDeleter clears the
 * EntityManager on every call. It MUST run first (lowest deletionOrder() of
 * every tagged purger). See AccountDataPurgerInterface for why.
 */
final readonly class ProjectAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectDeleter $projectDeleter,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 10;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        // Ids up front, then a re-fetch per iteration: ProjectDeleter clears the
        // EntityManager, so every project this loop holds is detached after the
        // first delete.
        $projectIds = array_map(static fn (Project $p): Uuid => $p->id ?? throw new \LogicException('a persisted project always has an id'), $this->projects->findBy(['owner' => $user]));
        foreach ($projectIds as $projectId) {
            $project = $this->projects->find($projectId);
            if (null !== $project) {
                $this->projectDeleter->delete($project);
            }
        }
    }
}
