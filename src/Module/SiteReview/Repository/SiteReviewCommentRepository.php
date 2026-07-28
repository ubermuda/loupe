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
     * The agent queue: Pending comments for the project, oldest first.
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
     * Status tally of everything the reviewer has actually submitted. Drafts are
     * excluded because they only exist inside the reviewer's widget and are not
     * part of the project's shared record yet.
     *
     * One grouped query rather than a count per status: the app-shell nav pill
     * needs both the submitted total (its number) and the pending count (its
     * tint) on every authenticated page render, and asking separately made that
     * two queries for one badge.
     *
     * @return array{pending: int, addressed: int, resolved: int}
     */
    public function submittedStatusCountsForProject(Project $project): array
    {
        /** @var list<array{status: SiteReviewCommentStatus, count: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status', 'COUNT(c.id) AS count')
            ->andWhere('c.project = :project')
            ->andWhere('c.status != :draft')
            ->setParameter('project', $project)
            ->setParameter('draft', SiteReviewCommentStatus::Draft)
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
     * The widget's current draft list, position-ordered.
     *
     * @return list<SiteReviewComment>
     */
    public function findDraftForProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewCommentStatus::Draft)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A Draft comment of the project — the only comments the widget may edit
     * or delete.
     */
    public function findOneDraft(Uuid $id, Project $project): ?SiteReviewComment
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('id', $id)
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewCommentStatus::Draft)
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
     * The project's whole comment list for the site-review page — a flat,
     * position-ordered feed across every status including Draft.
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

    public function countDraftForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.project = :project')
            ->andWhere('c.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', SiteReviewCommentStatus::Draft)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * "Send": flips every Draft comment of the project to Pending in one bulk
     * UPDATE — the batch boundary, expressed as a status transition rather
     * than a submitted entity. Returns the affected row count.
     */
    public function markDraftsPendingForProject(Project $project): int
    {
        return $this->getEntityManager()->createQuery(
            'UPDATE App\Module\SiteReview\Entity\SiteReviewComment c
             SET c.status = :pending
             WHERE c.project = :project AND c.status = :draft',
        )
            ->setParameter('pending', SiteReviewCommentStatus::Pending)
            ->setParameter('project', $project)
            ->setParameter('draft', SiteReviewCommentStatus::Draft)
            ->execute();
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
}
