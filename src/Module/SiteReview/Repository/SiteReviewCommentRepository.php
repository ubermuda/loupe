<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SiteReviewComment>
 */
class SiteReviewCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewComment::class);
    }

    /**
     * Pending comments for the project, oldest first. One list serves two
     * readers: the agent's queue, and the widget's own list of the comments its
     * reviewer may still edit or delete.
     *
     * @return list<SiteReviewComment>
     */
    public function findPendingForProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewCommentStatus::Pending)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open-count for the app-shell nav pill: Pending comments awaiting the
     * agent. Mirrors {@see findPendingForProject} so the pill and the agent
     * queue never disagree.
     */
    public function countOpenForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewCommentStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Status tally of the project's comments.
     *
     * One grouped query rather than a count per status: the app-shell nav pill
     * needs both the total (its number) and the pending count (its tint) on
     * every authenticated page render, and asking separately made that two
     * queries for one badge.
     *
     * @return array{pending: int, addressed: int, resolved: int}
     */
    public function statusCountsForProject(Project $project): array
    {
        /** @var list<array{status: SiteReviewCommentStatus, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status', 'COUNT(c.id) AS count')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->groupBy('c.status')
            ->getQuery()
            ->getResult();

        $counts = ['pending' => 0, 'addressed' => 0, 'resolved' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Same counts as statusCountsForProject, for several projects in one query.
     * The projects list renders every project on the page, so the per-project
     * form is an N+1 across all of them.
     *
     * @param list<Project> $projects
     *
     * @return array<string, array{pending: int, addressed: int, resolved: int}> keyed by project id
     */
    public function statusCountsForProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, status: SiteReviewCommentStatus, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.project) AS id', 'c.status AS status', 'COUNT(c.id) AS count')
            ->andWhere('c.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->groupBy('c.project', 'c.status')
            ->getQuery()
            ->getResult();

        $tallies = [];
        foreach ($rows as $row) {
            $tallies[(string) $row['id']][$row['status']->value] = (int) $row['count'];
        }

        $counts = [];
        foreach ($projects as $project) {
            $id = (string) $project->id;
            $counts[$id] = [
                'pending' => $tallies[$id]['pending'] ?? 0,
                'addressed' => $tallies[$id]['addressed'] ?? 0,
                'resolved' => $tallies[$id]['resolved'] ?? 0,
            ];
        }

        return $counts;
    }

    /**
     * A Pending comment of the project — the only comments the widget may edit
     * or delete. Once the agent has addressed or resolved one, it is frozen.
     */
    public function findOnePending(Uuid $id, Project $project): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('id', $id)
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewCommentStatus::Pending)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Project-scoped lookup for the MCP addressing tool. */
    public function findOneForProject(Uuid $id, Project $project): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.project = :project')
            ->setParameter('id', $id)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The project's comments in one status, oldest first. Kept beside
     * {@see findForProject} rather than folded into it: the agent's queue is a
     * status-filtered read and the widget's list is not.
     *
     * @return list<SiteReviewComment>
     */
    public function findForProjectWithStatus(Project $project, SiteReviewCommentStatus $status): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', $status)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The project's whole comment list for the site-review page — a flat,
     * position-ordered feed across every status.
     *
     * @return list<SiteReviewComment>
     */
    public function findForProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Next position for a new comment on this project. Not count(): after a
     * delete-then-add, count() would reuse an existing position and make
     * position-ordered ties nondeterministic.
     */
    public function nextPositionForProject(Project $project): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max) + 1;
    }

    /**
     * Site reviews hang off a project, not the owner directly, so this joins
     * through the project to filter by its owner.
     *
     * @return list<SiteReviewComment>
     */
    public function findByOwner(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.project', 'p')
            ->where('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pending → Addressed as a single conditional statement, so a human who
     * clicks Resolve between the caller's read and this write keeps their
     * resolution instead of having it silently replaced. Returns false when the
     * row was no longer pending.
     */
    public function markAddressedIfPending(SiteReviewComment $comment): bool
    {
        $updated = $this->createQueryBuilder('c')
            ->update()
            ->set('c.status', ':addressed')
            ->andWhere('c.id = :id')
            ->andWhere('c.status = :pending')
            ->setParameter('addressed', SiteReviewCommentStatus::Addressed)
            ->setParameter('id', $comment->id, 'uuid')
            ->setParameter('pending', SiteReviewCommentStatus::Pending)
            ->getQuery()
            ->execute();

        if (0 === $updated) {
            return false;
        }

        // A DQL update bypasses the identity map. The snapshot must move with
        // the copy, or the next flush reissues this as an unconditional UPDATE
        // and the race reopens. refresh() cannot do it: Doctrine refuses to
        // rehydrate the entity's readonly property.
        $comment->status = SiteReviewCommentStatus::Addressed;
        $this->getEntityManager()->getUnitOfWork()->setOriginalEntityProperty(
            spl_object_id($comment),
            'status',
            SiteReviewCommentStatus::Addressed,
        );

        return true;
    }

    /**
     * The status the row carries right now, read past the identity map. Only
     * useful after {@see markAddressedIfPending} returns false, to tell a
     * concurrent Resolve from a concurrent Addressed. Null if the row is gone.
     */
    public function currentStatus(SiteReviewComment $comment): ?SiteReviewCommentStatus
    {
        $row = $this->createQueryBuilder('c')
            ->select('c.status')
            ->andWhere('c.id = :id')
            ->setParameter('id', $comment->id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return is_array($row) && $row['status'] instanceof SiteReviewCommentStatus ? $row['status'] : null;
    }
}
