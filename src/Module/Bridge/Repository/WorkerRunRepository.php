<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
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
            ->andWhere('r.kind = :worker')
            ->setParameter('project', $project)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('worker', WorkerRunKind::Worker->value)
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
            ->andWhere('r.kind = :worker')
            ->setParameter('project', $project)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('startedAt', $startedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('worker', WorkerRunKind::Worker->value)
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
     * The runs one bridge of the owner still holds as far as the server knows:
     * open or timed-out, with a run key, locked until the transaction ends. The
     * owner filter is a subquery, so the lock covers the runs and not the
     * projects a state report locks first.
     *
     * @return list<WorkerRun>
     */
    public function findOpenOrTimedOutOfBridge(User $owner, Uuid $bridgeId): array
    {
        $states = [...WorkerRunState::openStates(), WorkerRunState::TimedOut];

        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.bridgeId = :bridgeId')
            ->andWhere('r.runKey IS NOT NULL')
            ->andWhere('r.state IN (:states)')
            ->andWhere(\sprintf('IDENTITY(r.project) IN (SELECT p.id FROM %s p WHERE p.owner = :owner)', Project::class))
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('states', array_map(static fn (WorkerRunState $state): string => $state->value, $states))
            ->setParameter('owner', $owner)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult());
    }

    /**
     * The open runs whose bridge went quiet: its owner's row for the bridge has
     * no heartbeat since the moment given. A bridge with no row at all sent no
     * heartbeat yet, so its run counts as quiet once it arrived that long ago.
     *
     * @return list<Uuid>
     */
    public function findIdsOfQuietOpenRuns(\DateTimeImmutable $quietBefore, int $limit): array
    {
        /** @var list<Uuid|string> $ids */
        $ids = $this->createQueryBuilder('r')
            ->select('r.id')
            ->join('r.project', 'p')
            ->leftJoin(Bridge::class, 'b', Join::WITH, 'IDENTITY(b.owner) = IDENTITY(p.owner) AND b.id = r.bridgeId')
            // No bridge holds an interactive run, so it would always read as quiet.
            ->andWhere('r.kind = :worker')
            ->andWhere('r.state IN (:openStates)')
            ->andWhere('b.lastSeenAt < :quietBefore OR (b.lastSeenAt IS NULL AND r.receivedAt < :quietBefore)')
            ->setParameter('openStates', array_map(
                static fn (WorkerRunState $state): string => $state->value,
                WorkerRunState::openStates(),
            ))
            ->setParameter('worker', WorkerRunKind::Worker->value)
            ->setParameter('quietBefore', $quietBefore, Types::DATETIME_IMMUTABLE)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $ids);
    }

    /**
     * The owner id and the bridge id of each bridge these runs belong to, once
     * each and in a fixed order, so two callers lock them in the same order.
     *
     * @param list<Uuid> $ids
     *
     * @return list<array{string, Uuid}>
     */
    public function findBridgesOfRuns(array $ids): array
    {
        /** @var list<array{ownerId: Uuid|string, bridgeId: Uuid|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT IDENTITY(p.owner) AS ownerId, r.bridgeId AS bridgeId')
            ->join('r.project', 'p')
            ->andWhere('r.id IN (:ids)')
            ->andWhere('r.bridgeId IS NOT NULL')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->orderBy('ownerId', 'ASC')
            ->addOrderBy('bridgeId', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): array => [
            (string) $row['ownerId'],
            $row['bridgeId'] instanceof Uuid ? $row['bridgeId'] : Uuid::fromString($row['bridgeId']),
        ], $rows);
    }

    /**
     * The runs among these ids that are still open and whose bridge is still
     * quiet, locked until the transaction ends, in id order so two sweeps cannot
     * deadlock. The bridge test is a subquery, so the lock covers the runs alone.
     *
     * @param list<Uuid> $ids
     *
     * @return list<WorkerRun>
     */
    public function findOpenByIdsForUpdate(array $ids, \DateTimeImmutable $quietBefore): array
    {
        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.id IN (:ids)')
            ->andWhere('r.kind = :worker')
            ->andWhere('r.state IN (:openStates)')
            ->andWhere(\sprintf(
                'EXISTS (SELECT qb.id FROM %1$s qb WHERE qb.id = r.bridgeId AND qb.lastSeenAt < :quietBefore'
                .' AND IDENTITY(qb.owner) = (SELECT IDENTITY(qp.owner) FROM %2$s qp WHERE qp = r.project))'
                .' OR (r.receivedAt < :quietBefore AND NOT EXISTS (SELECT nb.id FROM %1$s nb WHERE nb.id = r.bridgeId'
                .' AND IDENTITY(nb.owner) = (SELECT IDENTITY(np.owner) FROM %2$s np WHERE np = r.project)))',
                Bridge::class,
                Project::class,
            ))
            ->setParameter('quietBefore', $quietBefore, Types::DATETIME_IMMUTABLE)
            ->setParameter('worker', WorkerRunKind::Worker->value)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->setParameter('openStates', array_map(
                static fn (WorkerRunState $state): string => $state->value,
                WorkerRunState::openStates(),
            ))
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult());
    }

    public function findOpenInteractive(Project $project, Uuid $cardId, Uuid $sessionId): ?WorkerRun
    {
        return $this->openInteractiveOfSession($project, $cardId, $sessionId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOpenInteractiveForUpdate(Project $project, Uuid $cardId, Uuid $sessionId): ?WorkerRun
    {
        return self::forUpdate($this->openInteractiveOfSession($project, $cardId, $sessionId))
            ->getOneOrNullResult();
    }

    /** The open run of the session on the card, else its latest run. */
    public function findLatestInteractive(Project $project, Uuid $cardId, Uuid $sessionId): ?WorkerRun
    {
        return $this->interactive($project)
            ->addSelect('CASE WHEN r.state = :running THEN 0 ELSE 1 END AS HIDDEN openFirst')
            ->andWhere('r.cardId = :cardId')
            ->andWhere('r.sessionId = :sessionId')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('running', WorkerRunState::Running->value)
            ->orderBy('openFirst', 'ASC')
            ->addOrderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOpenInteractiveByIdForUpdate(Project $project, Uuid $runId): ?WorkerRun
    {
        return self::forUpdate($this->interactive($project)
            ->andWhere('r.id = :runId')
            ->andWhere('r.state = :running')
            ->setParameter('runId', $runId, UuidType::NAME)
            ->setParameter('running', WorkerRunState::Running->value))
            ->getOneOrNullResult();
    }

    public function findInteractiveById(Project $project, Uuid $runId): ?WorkerRun
    {
        return $this->interactive($project)
            ->andWhere('r.id = :runId')
            ->setParameter('runId', $runId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<Uuid> $cardIds
     *
     * @return list<WorkerRun> locked until the transaction ends, in id order so two callers cannot deadlock
     */
    public function findOpenInteractiveOfCardsForUpdate(Project $project, array $cardIds): array
    {
        return array_values(self::forUpdate($this->interactive($project)
            ->andWhere('r.cardId IN (:cardIds)')
            ->andWhere('r.state = :running')
            ->setParameter('cardIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->setParameter('running', WorkerRunState::Running->value)
            ->orderBy('r.id', 'ASC'))
            ->getResult());
    }

    public function hasOpenInteractive(Project $project, Uuid $cardId): bool
    {
        return null !== $this->interactive($project)
            ->select('1')
            ->andWhere('r.cardId = :cardId')
            ->andWhere('r.state = :running')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('running', WorkerRunState::Running->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function openInteractiveOfSession(Project $project, Uuid $cardId, Uuid $sessionId): QueryBuilder
    {
        return $this->interactive($project)
            ->andWhere('r.cardId = :cardId')
            ->andWhere('r.sessionId = :sessionId')
            ->andWhere('r.state = :running')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('running', WorkerRunState::Running->value);
    }

    private function interactive(Project $project): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.kind = :interactive')
            ->setParameter('project', $project)
            ->setParameter('interactive', WorkerRunKind::Interactive->value);
    }

    private static function forUpdate(QueryBuilder $qb): Query
    {
        return $qb->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE);
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
            ->andWhere('r.bridgeId IS NOT NULL')
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
     * For each card of the project, its latest outcome, kept only when that
     * outcome is gave-up or blocked. The pick comes before the filter, so a later
     * success clears the warning. An open run is no outcome and changes nothing.
     * Latest means the last the server closed, by the state change that holds
     * the outcome. A resume jumps the queue, and two bridge clocks can disagree,
     * so neither the queue time nor the bridge end time orders outcomes.
     *
     * @return list<array{id: string, card_id: string, state: string, output: string, card_column: ?string}>
     */
    public function findWarningRowsOfProject(Project $project): array
    {
        $outcomes = array_values(array_map(
            static fn (WorkerRunState $state): string => $state->value,
            array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => $state->isOutcome()),
        ));

        // A late report can record an outcome the run does not hold, so the
        // close is the latest change to the run's own state.
        /** @var list<array{id: string, card_id: string, state: string, output: string, card_column: ?string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT latest.id, latest.card_id, latest.state, latest.output, latest.card_column
                FROM (
                    SELECT DISTINCT ON (r.card_id) r.id, r.card_id, r.state, r.output, r.card_column
                    FROM bridge_worker_runs r
                    LEFT JOIN LATERAL (
                        SELECT s.received_at, s.sequence
                        FROM bridge_worker_run_states s
                        WHERE s.run_id = r.id AND s.state = r.state
                        ORDER BY s.sequence DESC
                        LIMIT 1
                    ) closed ON true
                    WHERE r.project_id = :project AND r.state IN (:outcomes)
                    ORDER BY r.card_id, COALESCE(closed.received_at, r.received_at) DESC, closed.sequence DESC NULLS LAST, r.id DESC
                ) latest
                WHERE latest.state IN (:warnings)
                SQL,
            [
                'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
                'outcomes' => $outcomes,
                'warnings' => [WorkerRunState::GaveUp->value, WorkerRunState::Blocked->value],
            ],
            ['outcomes' => ArrayParameterType::STRING, 'warnings' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
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
