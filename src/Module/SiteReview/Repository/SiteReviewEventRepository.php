<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Repository;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SiteReviewEvent>
 */
class SiteReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteReviewEvent::class);
    }

    /**
     * One row per submit, so this is the "n reviews" rollup on the projects
     * index — a review is a Send, and a Send always writes exactly one event.
     */
    public function countForProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Takes ownership of up to $limit events that are due for a publish attempt
     * and returns them.
     *
     * Two workers must never publish the same event, so the claim is a single
     * statement: `SKIP LOCKED` makes the inner select pass over rows another
     * transaction already holds, and the update stamps a lease into
     * `next_attempt_at` that keeps the row unclaimable after this transaction
     * commits. A lease rather than a lock held across the publish, so the
     * Mercure HTTP call never runs inside an open transaction.
     *
     * A publish that outlives its lease can therefore be claimed and published
     * a second time. That is tolerable: the update carries no payload beyond
     * its type, and a redundant pull finds nothing still pending.
     *
     * Postgres-only — `FOR UPDATE ... SKIP LOCKED` and `UPDATE ... RETURNING`
     * have no portable equivalent.
     *
     * @return list<SiteReviewEvent>
     */
    public function claimDueForPublish(int $limit, \DateTimeImmutable $now, \DateTimeImmutable $leaseUntil): array
    {
        $sql = <<<'SQL'
            UPDATE site_review_events
            SET next_attempt_at = :leaseUntil
            WHERE id IN (
                SELECT due.id
                FROM site_review_events due
                WHERE due.published_at IS NULL
                  AND due.forwardable = true
                  AND (due.next_attempt_at IS NULL OR due.next_attempt_at <= :now)
                ORDER BY due.sequence ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id
            SQL;

        $claimedIds = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['leaseUntil' => $leaseUntil, 'now' => $now, 'limit' => $limit],
            [
                'leaseUntil' => Types::DATETIME_IMMUTABLE,
                'now' => Types::DATETIME_IMMUTABLE,
                'limit' => ParameterType::INTEGER,
            ],
        )->fetchFirstColumn();

        if ([] === $claimedIds) {
            return [];
        }

        return array_values($this->findBy(
            ['id' => array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), $claimedIds)],
            ['sequence' => 'ASC'],
        ));
    }

    /**
     * Events still owed to a project's agent, newest first.
     *
     * @return list<SiteReviewEvent>
     */
    public function findUnsentForProject(Project $project): array
    {
        return $this->unsentQueryBuilder($project)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Paginator<SiteReviewEvent> */
    public function findUnsentPaginated(?Project $project, int $page, int $perPage, string $sort, string $dir): Paginator
    {
        $qb = $this->unsentQueryBuilder($project)
            ->orderBy('e.'.$sort, 'asc' === $dir ? 'ASC' : 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return new Paginator($qb->getQuery());
    }

    public function countUnsent(?Project $project = null): int
    {
        return (int) $this->unsentQueryBuilder($project)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The projects that currently have something stuck, for the admin filter.
     * Derived from the outbox rather than from every project so the dropdown
     * only ever offers a choice that narrows to a non-empty list.
     *
     * @return list<Project>
     */
    public function findProjectsWithUnsent(): array
    {
        $em = $this->getEntityManager();
        $unsentForCandidate = $this->unsentQueryBuilder(null)
            ->select('1')
            ->andWhere('e.project = candidate');

        return $em->createQueryBuilder()
            ->select('candidate')
            ->from(Project::class, 'candidate')
            ->where($em->getExpressionBuilder()->exists($unsentForCandidate->getDQL()))
            ->orderBy('candidate.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * "Unsent" is not `published_at IS NULL`. A collect-only widget token writes
     * a row that must never reach the agent, so an unforwardable row is settled,
     * not owed — draining on the null check alone would deliver exactly the
     * reviews the opt-in exists to withhold.
     */
    private function unsentQueryBuilder(?Project $project): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.publishedAt IS NULL')
            ->andWhere('e.forwardable = true');

        if (null !== $project) {
            $qb->andWhere('e.project = :project')->setParameter('project', $project);
        }

        return $qb;
    }
}
