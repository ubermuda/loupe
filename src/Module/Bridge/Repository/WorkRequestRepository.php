<?php

declare(strict_types=1);

namespace App\Module\Bridge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The claim, the renewal and the lapse sweep write with native SQL, so a
 * managed WorkRequest can be stale after them. Read it again through
 * findOneLocked, which refreshes.
 *
 * @extends ServiceEntityRepository<WorkRequest>
 */
class WorkRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkRequest::class);
    }

    /** Claims an open request for the bridge. Answers false when the request is not open, so a second claim loses. */
    public function claim(Uuid $id, Uuid $bridgeId, Uuid $token, \DateTimeImmutable $leaseUntil): bool
    {
        $sql = <<<'SQL'
            UPDATE work_requests
            SET state = :claimed, bridge_id = :bridge, claim_token = :token, lease_until = :leaseUntil, claims = claims + 1
            WHERE id = :id AND state = :open
            RETURNING id
            SQL;

        return false !== $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'claimed' => WorkRequestState::Claimed->value,
                'bridge' => $bridgeId->toRfc4122(),
                'token' => $token->toRfc4122(),
                'leaseUntil' => $leaseUntil,
                'id' => $id->toRfc4122(),
                'open' => WorkRequestState::Open->value,
            ],
            ['leaseUntil' => Types::DATETIME_IMMUTABLE],
        )->fetchOne();
    }

    /** Locked until the transaction ends, and read fresh even when the request is already managed. */
    public function findOneLocked(Uuid $id): ?WorkRequest
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Extends the lease of each claim that the owner's bridge still holds
     * with its token. The rows lock in id order, like the lapse sweep, so the
     * two cannot deadlock.
     *
     * @param list<array{Uuid, Uuid}> $claims pairs of the request id and the claim token
     *
     * @return list<string> the RFC 4122 ids of the renewed requests
     */
    public function renewLeases(string $ownerId, Uuid $bridgeId, array $claims, \DateTimeImmutable $leaseUntil): array
    {
        if ([] === $claims) {
            return [];
        }

        $sql = <<<'SQL'
            WITH target AS MATERIALIZED (
                SELECT w.id
                FROM work_requests w
                JOIN unnest(CAST(:ids AS uuid[]), CAST(:tokens AS uuid[])) AS held(id, token)
                  ON w.id = held.id AND w.claim_token = held.token
                JOIN projects p ON p.id = w.project_id
                WHERE w.bridge_id = :bridge
                  AND w.state = :claimed
                  AND p.owner_id = :owner
                ORDER BY w.id
                FOR UPDATE OF w
            )
            UPDATE work_requests
            SET lease_until = :leaseUntil
            FROM target
            WHERE work_requests.id = target.id
            RETURNING work_requests.id
            SQL;

        $array = static fn (array $uuids): string => '{'.implode(',', array_map(static fn (Uuid $uuid): string => $uuid->toRfc4122(), $uuids)).'}';

        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'leaseUntil' => $leaseUntil,
                'ids' => $array(array_column($claims, 0)),
                'tokens' => $array(array_column($claims, 1)),
                'bridge' => $bridgeId->toRfc4122(),
                'claimed' => WorkRequestState::Claimed->value,
                'owner' => $ownerId,
            ],
            ['leaseUntil' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * The open requests of the projects that a bridge with these capabilities
     * can run, oldest first.
     *
     * @param list<string> $projectIds   RFC 4122 ids, as Bridge::$projects holds them
     * @param list<string> $capabilities
     *
     * @return list<WorkRequest>
     */
    public function findOpenOffers(array $projectIds, array $capabilities, int $limit): array
    {
        if ([] === $projectIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.project IN (:projects)')
            ->andWhere('w.state = :open')
            ->setParameter('projects', $projectIds)
            ->setParameter('open', WorkRequestState::Open->value)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->setMaxResults($limit);
        if ([] === $capabilities) {
            $qb->andWhere('w.capability IS NULL');
        } else {
            $qb->andWhere('w.capability IS NULL OR w.capability IN (:capabilities)')
                ->setParameter('capabilities', $capabilities);
        }

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * Opens again every claimed request whose lease lapsed. It skips a row
     * that a renewal or a settlement holds, and the next sweep reads it again.
     *
     * @return list<WorkRequest> the reopened requests, read fresh
     */
    public function reopenLapsed(\DateTimeImmutable $now): array
    {
        $sql = <<<'SQL'
            WITH target AS MATERIALIZED (
                SELECT id
                FROM work_requests
                WHERE state = :claimed AND lease_until <= :now
                ORDER BY id
                FOR UPDATE SKIP LOCKED
            )
            UPDATE work_requests
            SET state = :open, bridge_id = NULL, claim_token = NULL, lease_until = NULL, reopened_at = :now
            FROM target
            WHERE work_requests.id = target.id
            RETURNING work_requests.id
            SQL;

        $ids = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['open' => WorkRequestState::Open->value, 'claimed' => WorkRequestState::Claimed->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult());
    }

    /**
     * The open requests about a subject other than a card that waited for a
     * bridge since the deadline or longer, oldest first. A reopen restarts the wait.
     *
     * @return list<WorkRequest>
     */
    public function findOpenOfOtherSubjectsBefore(\DateTimeImmutable $deadline): array
    {
        return array_values($this->createQueryBuilder('w')
            ->andWhere('w.state = :open')
            ->andWhere('w.subjectType <> :card')
            ->andWhere('COALESCE(w.reopenedAt, w.createdAt) <= :deadline')
            ->setParameter('open', WorkRequestState::Open->value)
            ->setParameter('card', WorkSubject::CARD)
            ->setParameter('deadline', $deadline, Types::DATETIME_IMMUTABLE)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Serialises the opens of one kind on one subject until the transaction
     * ends, so a second open reads the first instead of tripping the unique index.
     */
    public function lockLive(WorkSubject $subject, string $kind): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['work_request:'.$subject->type.':'.$subject->id->toRfc4122().':'.$kind],
        );
    }

    /** Reads the state alone, like the unique index. */
    public function hasLive(WorkSubject $subject, string $kind): bool
    {
        return null !== $this->ofSubject($subject)
            ->select('1')
            ->andWhere('w.kind = :kind')
            ->andWhere('w.state IN (:live)')
            ->setParameter('kind', $kind)
            ->setParameter('live', [WorkRequestState::Open->value, WorkRequestState::Claimed->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The request with the id, when it belongs to the subject in the project. A run report names its request unchecked. */
    public function findOneOfSubject(Uuid $id, Project $project, WorkSubject $subject): ?WorkRequest
    {
        return $this->ofSubject($subject)
            ->andWhere('w.id = :id')
            ->andWhere('w.project = :project')
            ->setParameter('id', $id, UuidType::NAME)
            ->setParameter('project', $project)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The open and claimed requests of the card, oldest first.
     *
     * @return list<WorkRequest>
     */
    public function findLiveForCard(Uuid $cardId): array
    {
        return array_values($this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.state IN (:live)')
            ->setParameter('live', [WorkRequestState::Open->value, WorkRequestState::Claimed->value])
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** The newest request of the card that the rule opened for the kind, in any state. */
    public function findLatestOfCardKindRule(Uuid $cardId, string $kind, string $ruleId): ?WorkRequest
    {
        return $this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.kind = :kind')
            ->andWhere('w.ruleId = :ruleId')
            ->setParameter('kind', $kind)
            ->setParameter('ruleId', $ruleId)
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The request of the card that settled done or refused last. */
    public function findLatestSettledForCard(Uuid $cardId): ?WorkRequest
    {
        return $this->ofSubject(WorkSubject::card($cardId))
            ->andWhere('w.state IN (:settled)')
            ->setParameter('settled', [WorkRequestState::Done->value, WorkRequestState::Refused->value])
            ->orderBy('w.settledAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Streamed, because no sweep deletes old requests.
     *
     * @return iterable<WorkRequest>
     */
    public function findByOwner(User $user): iterable
    {
        return $this->createQueryBuilder('w')
            ->addSelect('p')
            ->join('w.project', 'p')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->getQuery()
            ->toIterable();
    }

    private function ofSubject(WorkSubject $subject): QueryBuilder
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.subjectType = :subjectType')
            ->andWhere('w.subjectId = :subjectId')
            ->setParameter('subjectType', $subject->type)
            ->setParameter('subjectId', $subject->id, UuidType::NAME);
    }
}
