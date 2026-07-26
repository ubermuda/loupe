<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes every project the user owns via ProjectDeleter, which already
 * handles the full project subtree (documents, versions, comments, reviews,
 * site reviews, and the project's own two bound API tokens) inside its own
 * nested transaction.
 *
 * This is the only ORM-based purger and it calls EntityManager::clear() as
 * it iterates — it MUST run first (lowest deletionOrder() of every tagged
 * purger). See AccountDataPurgerInterface for why.
 */
final readonly class ProjectAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectDeleter $projectDeleter,
        private EntityManagerInterface $em,
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
        // Resolve ids up front, then re-fetch and clear() around each
        // delete: ProjectDeleter's listeners remove SiteReview/SiteReviewComment
        // rows via bulk DQL, which bypasses the identity map, so an earlier
        // project's now-stale SiteReview object survives into the next
        // flush() and Doctrine misreports its already-deleted `project` as a
        // new, non-cascaded entity. Clearing after each delete avoids that.
        $projectIds = array_map(static fn (Project $p): Uuid => $p->id ?? throw new \LogicException('a persisted project always has an id'), $this->projects->findBy(['owner' => $user]));
        foreach ($projectIds as $projectId) {
            $project = $this->projects->find($projectId);
            if (null !== $project) {
                $this->projectDeleter->delete($project);
                $this->em->clear();
            }
        }
    }
}
