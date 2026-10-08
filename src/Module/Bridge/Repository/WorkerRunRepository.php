<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Service\WorkerRunSearchIndexer;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
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

    public function findOneByIdAndProjectId(string $runId, string $projectId): ?WorkerRun
    {
        if (!Uuid::isValid($runId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :runId')
            ->andWhere('r.project = :projectId')
            ->setParameter('runId', Uuid::fromString($runId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
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

    /** The newest run of the card of any kind, an interactive run too. */
    public function findLatestOfCard(Project $project, Uuid $cardId): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The newest worker run of a claude session that the bridge ran, which a resume of the session continues. */
    public function findLatestOfSessionOnBridge(Project $project, Uuid $bridgeId, Uuid $sessionId): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.sessionId = :sessionId')
            ->andWhere('r.bridgeId = :bridgeId')
            ->andWhere('r.kind = :worker')
            ->setParameter('project', $project)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('bridgeId', $bridgeId, UuidType::NAME)
            ->setParameter('worker', WorkerRunKind::Worker->value)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The worker processes of a claude session in the project, in the order
     * they started, locked until the transaction ends. A run that never started
     * ran no process. The lock follows id order, as the timeout sweep's does, so
     * the two cannot deadlock.
     *
     * @return list<WorkerRun>
     */
    public function findStartedOfSessionForUpdate(Project $project, Uuid $sessionId): array
    {
        $runs = array_values(self::forUpdate($this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.sessionId = :sessionId')
            ->andWhere('r.kind = :worker')
            ->andWhere('r.startedAt IS NOT NULL')
            ->setParameter('project', $project)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('worker', WorkerRunKind::Worker->value)
            ->orderBy('r.id', 'ASC'))
            ->getResult());

        usort($runs, static fn (WorkerRun $a, WorkerRun $b): int => [$a->startedAt, (string) $a->id] <=> [$b->startedAt, (string) $b->id]);

        return $runs;
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
     * The run of the project with this key, from any bridge, locked until the
     * transaction ends. A key that two bridges share names no run.
     */
    public function findOneOfProjectByRunKey(Project $project, Uuid $runKey): ?WorkerRun
    {
        /** @var list<WorkerRun> $runs */
        $runs = $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.runKey = :runKey')
            ->setParameter('project', $project)
            ->setParameter('runKey', $runKey, UuidType::NAME)
            ->setMaxResults(2)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return 1 === \count($runs) ? $runs[0] : null;
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
     * The open runs of a project, of either kind.
     *
     * @return list<WorkerRun>
     */
    public function findOpenOfProject(Project $project): array
    {
        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.state IN (:states)')
            ->setParameter('project', $project)
            ->setParameter('states', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()))
            ->orderBy('r.id', 'ASC')
            ->getQuery()
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
            ->leftJoin(Bridge::class, 'b', Join::ON, 'IDENTITY(b.owner) = IDENTITY(p.owner) AND b.id = r.bridgeId')
            // No bridge holds an interactive run, so it would always read as quiet.
            ->andWhere('r.kind <> :interactive')
            ->andWhere('r.state IN (:openStates)')
            ->andWhere('b.lastSeenAt < :quietBefore OR (b.lastSeenAt IS NULL AND r.receivedAt < :quietBefore)')
            ->setParameter('openStates', array_map(
                static fn (WorkerRunState $state): string => $state->value,
                WorkerRunState::openStates(),
            ))
            ->setParameter('interactive', WorkerRunKind::Interactive->value)
            ->setParameter('quietBefore', $quietBefore, Types::DATETIME_IMMUTABLE)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $ids);
    }

    /**
     * The ids of the runs after the id given, in id order. Null starts at the first run.
     *
     * @return list<Uuid>
     */
    public function findIdsAfter(?Uuid $after, int $limit): array
    {
        $query = $this->createQueryBuilder('r')
            ->select('r.id')
            ->orderBy('r.id', 'ASC')
            ->setMaxResults($limit);
        if (null !== $after) {
            $query->andWhere('r.id > :after')->setParameter('after', $after, UuidType::NAME);
        }

        /** @var list<Uuid|string> $ids */
        $ids = $query->getQuery()->getSingleColumnResult();

        return array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $ids);
    }

    /**
     * @param list<string> $ids RFC 4122 strings
     *
     * @return list<WorkerRun>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult());
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
            ->andWhere('r.kind <> :interactive')
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
            ->setParameter('interactive', WorkerRunKind::Interactive->value)
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
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.sessionId = :sessionId')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
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

    /** Locked until the transaction ends. */
    public function findInteractiveOfSessionForUpdate(Project $project, Uuid $runId, Uuid $sessionId): ?WorkerRun
    {
        return self::forUpdate($this->interactive($project)
            ->andWhere('r.id = :runId')
            ->andWhere('r.sessionId = :sessionId')
            ->setParameter('runId', $runId, UuidType::NAME)
            ->setParameter('sessionId', $sessionId, UuidType::NAME))
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
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId IN (:cardIds)')
            ->andWhere('r.state = :running')
            ->setParameter('cardIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('running', WorkerRunState::Running->value)
            ->orderBy('r.id', 'ASC'))
            ->getResult());
    }

    /**
     * The cards among $cardIds that have an open run, of either kind.
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<string>
     */
    public function findCardIdsWithOpenRun(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $ids = $this->createQueryBuilder('r')
            ->select('DISTINCT r.subjectId')
            ->andWhere('r.project = :project')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId IN (:cardIds)')
            ->andWhere('r.state IN (:openStates)')
            ->setParameter('project', $project)
            ->setParameter('cardIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $cardIds))
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('openStates', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()))
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $id): string => $id instanceof Uuid ? $id->toRfc4122() : Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('A card id is a string.'))->toRfc4122(),
            $ids,
        ));
    }

    /**
     * One row per experiment and card of the project's runs, with the last run of the pair.
     *
     * @return list<array{experiment: string, cardId: string, lastRunAt: \DateTimeImmutable}>
     */
    public function findExperimentCardRows(Project $project): array
    {
        $connection = $this->getEntityManager()->getConnection();
        /** @var list<array{experiment: string, card_id: string, last_run_at: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            "SELECT experiment, subject_id AS card_id, MAX(received_at) AS last_run_at
            FROM bridge_worker_runs
            WHERE project_id = :project AND experiment IS NOT NULL AND subject_type = 'card'
            GROUP BY experiment, subject_id",
            ['project' => ($project->id ?? throw new \LogicException('Project has no id.'))->toRfc4122()],
        );

        $type = Type::getType(Types::DATETIME_IMMUTABLE);
        $platform = $connection->getDatabasePlatform();

        return array_map(static function (array $row) use ($type, $platform): array {
            $lastRunAt = $type->convertToPHPValue($row['last_run_at'], $platform);

            return [
                'experiment' => $row['experiment'],
                'cardId' => $row['card_id'],
                'lastRunAt' => $lastRunAt instanceof \DateTimeImmutable ? $lastRunAt : throw new \LogicException('A run has a receive time.'),
            ];
        }, $rows);
    }

    /**
     * Every worker run, of any experiment or of none, of the cards that ran in
     * the experiment or are pinned to it. Oldest first.
     *
     * @return list<WorkerRun>
     */
    public function findWorkerRunsOfExperimentCards(Project $project, string $experiment): array
    {
        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.kind = :worker')
            ->andWhere(\sprintf(
                'r.subjectType = :cardSubject AND (r.subjectId IN (SELECT e.subjectId FROM %1$s e WHERE e.project = :project AND e.experiment = :experiment AND e.subjectType = :cardSubject)'
                .' OR r.subjectId IN (SELECT pin.cardId FROM %2$s pin WHERE pin.project = :project AND pin.experiment = :experiment))',
                WorkerRun::class,
                ExperimentPin::class,
            ))
            ->setParameter('project', $project)
            ->setParameter('worker', WorkerRunKind::Worker->value)
            ->setParameter('experiment', $experiment)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->orderBy('r.receivedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * The runs among $ids that the project still stores. The retention sweep deletes the others.
     *
     * @param list<Uuid> $ids
     *
     * @return list<string> RFC 4122 strings
     */
    public function findExistingIds(Project $project, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $found = $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.project = :project')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $id): string => $id instanceof Uuid ? $id->toRfc4122() : Uuid::fromString(\is_string($id) ? $id : throw new \LogicException('A run id is a string.'))->toRfc4122(),
            $found,
        ));
    }

    public function hasOpenInteractive(Project $project, Uuid $cardId): bool
    {
        return null !== $this->interactive($project)
            ->select('1')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.state = :running')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('running', WorkerRunState::Running->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasOpenWorkerOfSessionOnCard(Project $project, Uuid $sessionId, Uuid $cardId): bool
    {
        return null !== $this->createQueryBuilder('r')
            ->select('1')
            ->andWhere('r.project = :project')
            ->andWhere('r.sessionId = :sessionId')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.kind = :kind')
            ->andWhere('r.state IN (:openStates)')
            ->setParameter('project', $project)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('kind', WorkerRunKind::Worker->value)
            ->setParameter('openStates', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function openInteractiveOfSession(Project $project, Uuid $cardId, Uuid $sessionId): QueryBuilder
    {
        return $this->interactive($project)
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.sessionId = :sessionId')
            ->andWhere('r.state = :running')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('running', WorkerRunState::Running->value);
    }

    private function continuationsOfCard(Uuid $cardId): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.kind = :kind')
            ->andWhere('r.continuesRun IS NOT NULL')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('kind', WorkerRunKind::Worker->value)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');
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

    /** The newest run of the card that names a claude session, of any kind. */
    public function findLatestSessionOfCard(Project $project, Uuid $cardId): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.sessionId IS NOT NULL')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The run of a claude session most likely to act on the card: a run on
     * that card first, then an open run, then the newest. Any kind counts.
     */
    public function findLikeliestOfSession(Project $project, Uuid $sessionId, Uuid $cardId): ?WorkerRun
    {
        return $this->createQueryBuilder('r')
            ->addSelect('CASE WHEN r.subjectType = :cardSubject AND r.subjectId = :cardId THEN 0 ELSE 1 END AS HIDDEN onCardFirst')
            ->addSelect('CASE WHEN r.state IN (:openStates) THEN 0 ELSE 1 END AS HIDDEN openFirst')
            ->andWhere('r.project = :project')
            ->andWhere('r.sessionId = :sessionId')
            ->setParameter('project', $project)
            ->setParameter('sessionId', $sessionId, UuidType::NAME)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('openStates', array_map(
                static fn (WorkerRunState $state): string => $state->value,
                WorkerRunState::openStates(),
            ))
            ->orderBy('onCardFirst', 'ASC')
            ->addOrderBy('openFirst', 'ASC')
            ->addOrderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<string> the distinct work kinds of the card's open worker runs, sorted */
    public function findOpenWorkKindsOfCard(Uuid $cardId): array
    {
        /** @var list<string> $kinds */
        $kinds = $this->createQueryBuilder('r')
            ->select('DISTINCT r.workKind')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.kind = :kind')
            ->andWhere('r.workKind IS NOT NULL')
            ->andWhere('r.state IN (:openStates)')
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('kind', WorkerRunKind::Worker->value)
            ->setParameter('openStates', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()))
            ->orderBy('r.workKind')
            ->getQuery()
            ->getSingleColumnResult();

        return $kinds;
    }

    /** @return list<WorkerRun> the card's open runs, newest first */
    public function findOpenForCard(Project $project, Uuid $cardId, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.project = :project')
            ->andWhere('r.subjectType = :cardSubject AND r.subjectId = :cardId')
            ->andWhere('r.state IN (:openStates)')
            ->setParameter('project', $project)
            ->setParameter('cardId', $cardId, UuidType::NAME)
            ->setParameter('cardSubject', WorkSubject::CARD)
            ->setParameter('openStates', array_map(
                static fn (WorkerRunState $state): string => $state->value,
                WorkerRunState::openStates(),
            ))
            ->orderBy('r.receivedAt', 'DESC')
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
    public function findPaginatedByProject(Project $project, int $page, int $perPage, WorkerRunListQuery $query): Paginator
    {
        $search = $query->search;
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

        if (null !== $query->state) {
            $qb->andWhere('r.state = :state')->setParameter('state', $query->state->value);
        }

        if ([] !== $query->states) {
            $qb->andWhere('r.state IN (:states)')
                ->setParameter('states', array_map(static fn (WorkerRunState $state): string => $state->value, $query->states));
        }

        if (null !== $query->cardNumber) {
            $qb->andWhere('r.subjectType = :cardSubject AND r.cardNumber = :cardNumber')
                ->setParameter('cardSubject', WorkSubject::CARD)
                ->setParameter('cardNumber', $query->cardNumber);
        }

        if (null !== $query->workKind) {
            $qb->andWhere('r.workKind = :workKind')->setParameter('workKind', $query->workKind);
        }

        // A timed-out, lost or replaced run moves state with no end, so its first report stands in.
        if (null !== $query->endedAfter) {
            $qb->andWhere('COALESCE(r.endedAt, r.receivedAt) >= :endedAfter')->setParameter('endedAfter', $query->endedAfter, Types::DATETIME_IMMUTABLE);
        }

        if (null !== $query->endedBefore) {
            $qb->andWhere('COALESCE(r.endedAt, r.receivedAt) <= :endedBefore')->setParameter('endedBefore', $query->endedBefore, Types::DATETIME_IMMUTABLE);
        }

        if ($query->open) {
            $qb->andWhere('r.state IN (:openStates)')
                ->setParameter('openStates', array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()));
        }

        if (null !== $query->bridgeId) {
            $qb->andWhere('r.bridgeId = :bridgeId')
                ->setParameter('bridgeId', $query->bridgeId, UuidType::NAME);
        }

        if (null !== $query->harness) {
            $qb->andWhere('r.harness = :harness')->setParameter('harness', $query->harness);
        }

        if (null !== $query->account) {
            $qb->andWhere('r.account = :account')->setParameter('account', $query->account);
        }

        if (null !== $query->model) {
            // A run with no reported model shows the model of its fact, so the filter reads it too.
            $qb->andWhere('r.model = :model OR (r.model IS NULL AND EXISTS (SELECT 1 FROM '.WorkerRunFact::class.' f WHERE f.runId = r.id AND f.model = :model))')
                ->setParameter('model', $query->model);
        }

        // Nothing is fetch-joined, so the page LIMIT already counts runs.
        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * The runs that resume one of these runs, oldest report first.
     *
     * @param list<WorkerRun> $runs
     *
     * @return list<WorkerRun>
     */
    public function findContinuationsOf(array $runs): array
    {
        if ([] === $runs) {
            return [];
        }

        return array_values($this->createQueryBuilder('r')
            ->andWhere('r.continuesRun IN (:runs)')
            ->setParameter('runs', $runs)
            ->orderBy('r.receivedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** The newest worker run of the card that resumes or reruns an earlier run, open or ended. */
    public function findLatestContinuationOfCard(Uuid $cardId): ?WorkerRun
    {
        return $this->continuationsOfCard($cardId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
     * The values the project's runs hold in one of these columns, sorted, for a page filter.
     *
     * @param 'harness'|'account'|'model' $field
     *
     * @return list<string>
     */
    public function distinctValuesOf(Project $project, string $field): array
    {
        if (!\in_array($field, ['harness', 'account', 'model'], true)) {
            throw new \InvalidArgumentException(\sprintf('No page filter reads the field "%s".', $field));
        }

        /** @var list<string> $values */
        $values = $this->createQueryBuilder('r')
            ->select('DISTINCT r.'.$field)
            ->andWhere('r.project = :project')
            ->andWhere('r.'.$field.' IS NOT NULL')
            ->setParameter('project', $project)
            ->orderBy('r.'.$field, 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        if ('model' !== $field) {
            return $values;
        }

        // The model filter also matches the fact model of a run with no reported model.
        /** @var list<string> $factModels */
        $factModels = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT f.model')
            ->from(WorkerRunFact::class, 'f')
            ->innerJoin(WorkerRun::class, 'r', Join::WITH, 'r.id = f.runId')
            ->andWhere('r.project = :project')
            ->andWhere('r.model IS NULL')
            ->andWhere('f.model IS NOT NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleColumnResult();
        $values = array_values(array_unique([...$values, ...$factModels]));
        sort($values);

        return $values;
    }

    /**
     * For each card of the project, its latest outcome, kept only when that
     * outcome is a warning: gave-up, blocked, failed or no-result. The pick
     * comes before the filter, so a later success clears the warning. Latest
     * means the last the server closed, by the state change that holds the
     * outcome. A resume jumps the queue, and two bridge clocks can disagree, so
     * neither the queue time nor the bridge end time orders outcomes.
     *
     * The newest run decides, so a run of the card that the server received
     * after that close hides the warning, open or closed, of any kind. A
     * replaced or skipped run never ran, and hides nothing. In the same second,
     * the insertion order of the state changes decides.
     *
     * @return list<array{id: string, card_id: string, state: string, output: string}>
     */
    public function findWarningRowsOfProject(Project $project): array
    {
        return $this->findWarningRows($project, null);
    }

    /**
     * The warning of one card, by the rule of {@see findWarningRowsOfProject()}.
     *
     * @return array{id: string, card_id: string, state: string, output: string}|null
     */
    public function findWarningRowOfCard(Project $project, Uuid $cardId): ?array
    {
        return $this->findWarningRows($project, $cardId)[0] ?? null;
    }

    /**
     * The newest run of each card, open or closed, of any kind. Null reads
     * every card of the project.
     *
     * @param list<Uuid>|null $cardIds
     *
     * @return list<array{id: string, card_id: string, state: string, output: string}>
     */
    public function findLatestRunRows(Project $project, ?array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $params = ['project' => (string) ($project->id ?? throw new \LogicException('Project has no id.'))];
        $types = [];
        $cardFilter = '';
        if (null !== $cardIds) {
            $cardFilter = 'AND r.subject_id IN (:cards)';
            $params['cards'] = array_map(static fn (Uuid $id): string => (string) $id, $cardIds);
            $types['cards'] = ArrayParameterType::STRING;
        }

        /** @var list<array{id: string, card_id: string, state: string, output: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT DISTINCT ON (r.subject_id) r.id, r.subject_id AS card_id, r.state, r.output
                FROM bridge_worker_runs r
                WHERE r.project_id = :project AND r.subject_type = 'card' {$cardFilter}
                ORDER BY r.subject_id, r.received_at DESC, r.id DESC
                SQL,
            $params,
            $types,
        )->fetchAllAssociative();

        return $rows;
    }

    /** @return list<array{id: string, card_id: string, state: string, output: string}> */
    private function findWarningRows(Project $project, ?Uuid $cardId): array
    {
        $outcomes = array_values(array_map(
            static fn (WorkerRunState $state): string => $state->value,
            array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => $state->isOutcome()),
        ));
        $params = [
            'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
            'outcomes' => $outcomes,
            'warnings' => array_values(array_map(
                static fn (WorkerRunState $state): string => $state->value,
                array_filter(WorkerRunState::cases(), static fn (WorkerRunState $state): bool => $state->isWarning()),
            )),
            'setAside' => [WorkerRunState::Replaced->value, WorkerRunState::Skipped->value],
        ];
        $cardFilter = '';
        if (null !== $cardId) {
            $cardFilter = 'AND r.subject_id = :card';
            $params['card'] = (string) $cardId;
        }

        // A late report can record an outcome the run does not hold, so the
        // close is the latest change to the run's own state.
        /** @var list<array{id: string, card_id: string, state: string, output: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<SQL
                SELECT latest.id, latest.card_id, latest.state, latest.output
                FROM (
                    SELECT DISTINCT ON (r.subject_id) r.id, r.subject_id AS card_id, r.state, r.output,
                        COALESCE(closed.received_at, r.received_at) AS closed_at, closed.sequence AS closed_sequence
                    FROM bridge_worker_runs r
                    LEFT JOIN LATERAL (
                        SELECT s.received_at, s.sequence
                        FROM bridge_worker_run_states s
                        WHERE s.run_id = r.id AND s.state = r.state
                        ORDER BY s.sequence DESC
                        LIMIT 1
                    ) closed ON true
                    WHERE r.project_id = :project AND r.subject_type = 'card' AND r.state IN (:outcomes) {$cardFilter}
                    ORDER BY r.subject_id, COALESCE(closed.received_at, r.received_at) DESC, closed.sequence DESC NULLS LAST, r.id DESC
                ) latest
                WHERE latest.state IN (:warnings) AND NOT EXISTS (
                    SELECT 1
                    FROM bridge_worker_runs newer
                    WHERE newer.project_id = :project AND newer.subject_type = 'card' AND newer.subject_id = latest.card_id
                        AND newer.id <> latest.id AND newer.state NOT IN (:setAside)
                        AND (newer.received_at > latest.closed_at
                            OR (newer.received_at = latest.closed_at
                                AND (SELECT MIN(s.sequence) FROM bridge_worker_run_states s WHERE s.run_id = newer.id) > latest.closed_sequence))
                )
                SQL,
            $params,
            ['outcomes' => ArrayParameterType::STRING, 'warnings' => ArrayParameterType::STRING, 'setAside' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    /**
     * How many closed worker runs of a card started and reported no usage, and
     * whether any run of the card reported usage. An interactive run with no usage
     * never counts as partial, because its bridge reports the usage only on request.
     *
     * @return array{partial: int, reported: bool}
     */
    public function findUsageStateOfCard(Project $project, Uuid $cardId): array
    {
        /** @var array{partial: int|string, reported: bool|null} $row */
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    COUNT(*) FILTER (
                        WHERE started_at IS NOT NULL AND usage_source IS NULL AND kind = :worker AND state NOT IN (:unfinished)
                    ) AS partial,
                    BOOL_OR(usage_source IS NOT NULL) AS reported
                FROM bridge_worker_runs
                WHERE project_id = :project AND subject_type = 'card' AND subject_id = :card
                SQL,
            [
                'project' => (string) ($project->id ?? throw new \LogicException('Project has no id.')),
                'card' => (string) $cardId,
                'worker' => WorkerRunKind::Worker->value,
                'unfinished' => [
                    ...array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates()),
                    WorkerRunState::NotStarted->value,
                ],
            ],
            ['unfinished' => ArrayParameterType::STRING],
        )->fetchAssociative();

        return ['partial' => (int) $row['partial'], 'reported' => true === $row['reported']];
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

    /**
     * The ended runs of one bridge, in projects of its owner, whose window
     * overlaps $from to $to.
     *
     * @return list<Uuid>
     */
    public function findEndedIdsOnBridgeBetween(Uuid $ownerId, Uuid $bridgeId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT r.id
                FROM bridge_worker_runs r
                JOIN projects p ON p.id = r.project_id
                WHERE r.bridge_id = :bridge AND p.owner_id = :owner
                    AND r.started_at <= :to AND r.ended_at >= :from
                SQL,
            [
                'bridge' => $bridgeId->toRfc4122(),
                'owner' => $ownerId->toRfc4122(),
                'from' => $from->setTimezone(new \DateTimeZone('UTC')),
                'to' => $to->setTimezone(new \DateTimeZone('UTC')),
            ],
            ['from' => Types::DATETIME_IMMUTABLE, 'to' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), $ids);
    }
}
