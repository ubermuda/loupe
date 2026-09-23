<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkerRun>
 */
class WorkerRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerRun::class);
    }

    /**
     * The first run of a claude session in the project: the earliest start, and
     * the lowest id on a tie. A resume of that session reports later runs.
     */
    public function findFirstOfSession(Project $project, Uuid $sessionId): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.sessionId = :sessionId')
            ->andWhere('r.sessionId IS NOT NULL')
            ->setParameter('project', $project)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->orderBy('r.startedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The run a report names, by the natural key a retry repeats. Null when the
     * server has not seen this report before.
     */
    public function findOneByReportKey(Project $project, Uuid $bridgeId, Uuid $cardId, \DateTimeImmutable $startedAt): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.bridgeId = :bridgeId')
            ->andWhere('r.cardId = :cardId')
            ->andWhere('r.startedAt = :startedAt')
            // The natural key is unique only among runs that carry no run key.
            ->andWhere('r.runKey IS NULL')
            ->setParameter('project', $project)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('startedAt', $startedAt, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The run the bridge gave this key, locked until the transaction ends, so no
     * inventory or timeout sweep writes the state between the read and the write.
     */
    public function findOneByRunKey(Project $project, Uuid $bridgeId, Uuid $runKey): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.bridgeId = :bridgeId')
            ->andWhere('r.runKey = :runKey')
            ->setParameter('project', $project)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('runKey', $runKey, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Every run on every project the user owns, for the account data export.
     *
     * @return list<WorkerRun>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('r')
            ->join('r.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('r.receivedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<WorkerRun> the card's open runs, then its latest runs, newest first */
    public function findRecentForCard(Project $project, Uuid $cardId, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('CASE WHEN r.state IN (:openStates) THEN 0 ELSE 1 END AS HIDDEN openFirst')
            ->andWhere('r.project = :project')
            ->andWhere('r.cardId = :cardId')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('openStates', array_map(
                static fn (WorkerRunState $state): string => $state->value,
                WorkerRunState::openStates(),
            ))
            ->orderBy('openFirst', 'ASC')
            ->addOrderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of a project's runs, newest report first.
     *
     * The order matches idx_bridge_worker_runs_project_received, and it stays the
     * same under a search: a reader of a run list is debugging, so the newest
     * report outranks the best match. The id breaks a tie on the second, without
     * which an offset page can repeat or skip a run.
     *
     * @return Paginator<WorkerRun>
     */
    public function findPaginatedByProject(
        Project $project,
        int $page,
        int $perPage,
        ?string $search = null,
        ?WorkerRunState $state = null,
        ?Uuid $bridgeId = null,
    ): Paginator {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $search) {
            // The configuration is concatenated rather than bound, because
            // Postgres overloads websearch_to_tsquery as (regconfig, text) and
            // (text), so a bound parameter has no type to resolve against and
            // picks the wrong arity. It comes from the enum, never from input.
            $match = \sprintf(
                "TSMATCH(r.searchVector, WEBSEARCH_TO_TSQUERY('%s', :search)) = true",
                WorkerRunSearchIndexer::LANGUAGE->value,
            );

            if (Uuid::isValid($search)) {
                $match = '('.$match.' OR r.id = :runId)';
                $qb->setParameter('runId', Uuid::fromString($search), UuidType::NAME);
            }

            $qb->andWhere($match)->setParameter('search', $search);
        }

        if (null !== $state) {
            $qb->andWhere('r.state = :state')->setParameter('state', $state->value);
        }

        if (null !== $bridgeId) {
            $qb->andWhere('r.bridgeId = :bridgeId')
                ->setParameter('bridgeId', $bridgeId, UuidType::NAME);
        }

        // Nothing is fetch-joined, so the page LIMIT already counts runs.
        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * The bridges that have reported a run for this project, for the page filter.
     *
     * @return list<Uuid>
     */
    public function bridgeIdsOf(Project $project): array
    {
        /** @var list<Uuid|string> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.bridgeId')
            ->andWhere('r.project = :project')
            ->setParameter('project', $project)
            ->orderBy('r.bridgeId', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(
            static fn (Uuid|string $row): Uuid => $row instanceof Uuid ? $row : Uuid::fromString($row),
            $rows,
        );
    }

    /**
     * Deletes every run the server received before the given moment, and answers
     * how many rows went.
     *
     * The cut is on the server clock rather than on the bridge clock. A bridge
     * with a wrong clock would otherwise stamp a run outside the window and lose
     * it on the next sweep.
     */
    public function deleteReceivedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.receivedAt < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
